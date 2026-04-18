<?php

namespace App\Service;

use App\Entity\ScopeVerification;
use App\Entity\User;
use App\Entity\UserDocument;
use App\Repository\ScopeVerificationRepository;
use App\Repository\UserDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ScopeVerificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ScopeVerificationRepository $scopeVerificationRepository,
        private UserDocumentRepository $userDocumentRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Récupère la configuration d'un scope
     */
    public function getScopeConfig(string $scopeKey): ?array
    {
        $result = $this->em->getConnection()->executeQuery(
            'SELECT * FROM scope_config WHERE scope_key = ?',
            [$scopeKey]
        )->fetchAssociative();

        if ($result) {
            // Parse required_docs array from PostgreSQL format
            $result['required_docs'] = $this->parsePostgresArray($result['required_docs'] ?? '{}');
        }

        return $result ?: null;
    }

    /**
     * Récupère le scope requis pour une catégorie
     */
    public function getRequiredScope(string $category, ?string $subcategory = null): ?array
    {
        // D'abord chercher avec la sous-catégorie exacte
        $result = $this->em->getConnection()->executeQuery(
            'SELECT csm.*, sc.display_name_fr, sc.icon, sc.required_docs, sc.is_strict, sc.parent_scope
             FROM category_scope_map csm
             JOIN scope_config sc ON sc.scope_key = csm.required_scope_key
             WHERE csm.category = ? AND csm.subcategory = ?',
            [$category, $subcategory]
        )->fetchAssociative();

        // Si pas trouvé, chercher la catégorie sans sous-catégorie
        if (!$result && $subcategory) {
            $result = $this->em->getConnection()->executeQuery(
                'SELECT csm.*, sc.display_name_fr, sc.icon, sc.required_docs, sc.is_strict, sc.parent_scope
                 FROM category_scope_map csm
                 JOIN scope_config sc ON sc.scope_key = csm.required_scope_key
                 WHERE csm.category = ? AND csm.subcategory IS NULL',
                [$category]
            )->fetchAssociative();
        }

        if ($result) {
            $result['required_docs'] = $this->parsePostgresArray($result['required_docs'] ?? '{}');
        }

        return $result ?: null;
    }

    /**
     * Vérifie si un utilisateur peut publier dans une catégorie
     * C'est LA méthode centrale appelée à chaque POST /listings
     */
    public function canUserPublish(User $user, string $category, ?string $subcategory = null): array
    {
        // Récupérer le scope requis
        $requirement = $this->getRequiredScope($category, $subcategory);

        // Pas de scope requis = publication autorisée
        if (!$requirement) {
            return [
                'canPublish' => true,
                'requiredScope' => null,
                'status' => null,
                'missingDocs' => [],
            ];
        }

        // Vérification optionnelle = publication autorisée (mais badge non affiché)
        if (!$requirement['is_mandatory']) {
            return [
                'canPublish' => true,
                'requiredScope' => $requirement['required_scope_key'],
                'status' => 'OPTIONAL',
                'missingDocs' => [],
            ];
        }

        $scopeKey = $requirement['required_scope_key'];
        $requiredDocs = $requirement['required_docs'];

        // Vérifier si l'utilisateur est approuvé pour ce scope
        $verification = $this->scopeVerificationRepository->findByUserAndScope($user, $scopeKey);

        if ($verification && $verification->isApproved()) {
            return [
                'canPublish' => true,
                'requiredScope' => $scopeKey,
                'status' => 'APPROVED',
                'missingDocs' => [],
                'approvedAt' => $verification->getApprovedAt()?->format('c'),
                'expiresAt' => $verification->getExpiresAt()?->format('c'),
            ];
        }

        // Si scope non strict, vérifier le parent
        if (!$requirement['is_strict'] && $requirement['parent_scope']) {
            $parentVerification = $this->scopeVerificationRepository->findByUserAndScope($user, $requirement['parent_scope']);
            if ($parentVerification && $parentVerification->isApproved()) {
                return [
                    'canPublish' => true,
                    'requiredScope' => $requirement['parent_scope'],
                    'status' => 'APPROVED',
                    'inheritedFrom' => $requirement['parent_scope'],
                    'missingDocs' => [],
                ];
            }
        }

        // Vérifier le scope LEGACY_VERIFIED pour les anciens utilisateurs
        $legacyVerification = $this->scopeVerificationRepository->findByUserAndScope($user, 'LEGACY_VERIFIED');
        if ($legacyVerification && $legacyVerification->isApproved() && !$requirement['is_strict']) {
            return [
                'canPublish' => true,
                'requiredScope' => 'LEGACY_VERIFIED',
                'status' => 'APPROVED',
                'inheritedFrom' => 'LEGACY_VERIFIED',
                'missingDocs' => [],
            ];
        }

        // L'utilisateur n'est pas approuvé - calculer ce qui manque
        $currentStatus = $verification ? $verification->getStatus() : ScopeVerification::STATUS_NOT_STARTED;
        $userValidDocs = $this->userDocumentRepository->getValidDocTypes($user);
        $missingDocs = array_diff($requiredDocs, $userValidDocs);

        // Vérifier si bloqué
        if ($verification && $verification->isBlocked()) {
            return [
                'canPublish' => false,
                'requiredScope' => $scopeKey,
                'scopeDisplayName' => $requirement['display_name_fr'],
                'scopeIcon' => $requirement['icon'],
                'status' => 'BLOCKED',
                'blockedUntil' => $verification->getBlockedUntil()?->format('c'),
                'rejectionReason' => $verification->getRejectionReason(),
                'missingDocs' => [],
            ];
        }

        return [
            'canPublish' => false,
            'requiredScope' => $scopeKey,
            'scopeDisplayName' => $requirement['display_name_fr'],
            'scopeIcon' => $requirement['icon'],
            'status' => $currentStatus,
            'requiredDocs' => $requiredDocs,
            'missingDocs' => array_values($missingDocs),
            'rejectionReason' => $verification?->getRejectionReason(),
        ];
    }

    /**
     * Soumettre des documents pour un scope
     */
    public function submitForScope(User $user, string $scopeKey, array $documentIds): ScopeVerification
    {
        // Récupérer ou créer la vérification
        $verification = $this->scopeVerificationRepository->findByUserAndScope($user, $scopeKey);

        if (!$verification) {
            $verification = new ScopeVerification();
            $verification->setUser($user);
            $verification->setScopeKey($scopeKey);
            $this->em->persist($verification);
        }

        // Vérifier si déjà approuvé
        if ($verification->isApproved()) {
            throw new \Exception('Vous êtes déjà vérifié pour ce scope.');
        }

        // Vérifier si bloqué
        if ($verification->isBlocked()) {
            throw new \Exception('Vos soumissions sont temporairement bloquées.');
        }

        // Vérifier cooldown (si rejeté récemment)
        $scopeConfig = $this->getScopeConfig($scopeKey);
        if ($verification->getStatus() === ScopeVerification::STATUS_REJECTED) {
            $cooldownHours = $scopeConfig['cooldown_hours'] ?? 24;
            $lastReview = $verification->getReviewedAt();
            if ($lastReview) {
                $cooldownEnd = (clone $lastReview)->modify("+{$cooldownHours} hours");
                if ($cooldownEnd > new \DateTime()) {
                    throw new \Exception('Veuillez attendre avant de resoumettre. Réessayez après ' . $cooldownEnd->format('d/m/Y H:i'));
                }
            }
        }

        // Attacher les documents
        foreach ($documentIds as $docId) {
            $doc = $this->userDocumentRepository->find($docId);
            if ($doc && $doc->getUser()->getId() === $user->getId()) {
                $verification->addDocument($doc);
            }
        }

        // Soumettre
        $verification->submit();
        $this->em->flush();

        $this->logger->info('Scope verification submitted', [
            'userId' => $user->getId(),
            'scopeKey' => $scopeKey,
            'documentCount' => count($documentIds),
        ]);

        return $verification;
    }

    /**
     * Approuver une vérification (admin)
     */
    public function approveVerification(ScopeVerification $verification, User $admin): ScopeVerification
    {
        $scopeConfig = $this->getScopeConfig($verification->getScopeKey());
        $expirationDays = $scopeConfig['expiration_days'] ?? 730;

        $verification->approve($admin, $expirationDays);

        // Valider tous les documents attachés
        foreach ($verification->getDocuments() as $doc) {
            if ($doc->getStatus() === UserDocument::STATUS_UPLOADED) {
                $doc->validate((new \DateTime())->modify("+{$expirationDays} days"));
            }
        }

        $this->em->flush();

        $this->logger->info('Scope verification approved', [
            'verificationId' => $verification->getId(),
            'userId' => $verification->getUser()->getId(),
            'scopeKey' => $verification->getScopeKey(),
            'adminId' => $admin->getId(),
        ]);

        return $verification;
    }

    /**
     * Rejeter une vérification (admin)
     */
    public function rejectVerification(ScopeVerification $verification, User $admin, string $reason, array $rejectedDocIds = []): ScopeVerification
    {
        $scopeConfig = $this->getScopeConfig($verification->getScopeKey());
        $maxRejections = $scopeConfig['max_rejections'] ?? 3;
        $cooldownHours = $scopeConfig['cooldown_hours'] ?? 24;

        $verification->reject($admin, $reason, $maxRejections, $cooldownHours);

        // Rejeter les documents spécifiés
        foreach ($rejectedDocIds as $docId) {
            $doc = $this->userDocumentRepository->find($docId);
            if ($doc) {
                $doc->reject($reason);
            }
        }

        $this->em->flush();

        $this->logger->info('Scope verification rejected', [
            'verificationId' => $verification->getId(),
            'userId' => $verification->getUser()->getId(),
            'scopeKey' => $verification->getScopeKey(),
            'adminId' => $admin->getId(),
            'reason' => $reason,
        ]);

        return $verification;
    }

    /**
     * Récupérer tous les scopes d'un utilisateur
     */
    public function getUserScopes(User $user): array
    {
        $verifications = $this->scopeVerificationRepository->findByUser($user);
        
        return array_map(function (ScopeVerification $v) {
            $config = $this->getScopeConfig($v->getScopeKey());
            return [
                'scopeKey' => $v->getScopeKey(),
                'displayName' => $config['display_name_fr'] ?? $v->getScopeKey(),
                'icon' => $config['icon'] ?? '✓',
                'status' => $v->getStatus(),
                'isApproved' => $v->isApproved(),
                'isPending' => $v->isPending(),
                'isBlocked' => $v->isBlocked(),
                'approvedAt' => $v->getApprovedAt()?->format('c'),
                'expiresAt' => $v->getExpiresAt()?->format('c'),
                'rejectionReason' => $v->getRejectionReason(),
            ];
        }, $verifications);
    }

    /**
     * Récupérer uniquement les scopes approuvés
     */
    public function getApprovedScopes(User $user): array
    {
        $verifications = $this->scopeVerificationRepository->findApprovedByUser($user);
        
        return array_map(function (ScopeVerification $v) {
            $config = $this->getScopeConfig($v->getScopeKey());
            return [
                'scopeKey' => $v->getScopeKey(),
                'displayName' => $config['display_name_fr'] ?? $v->getScopeKey(),
                'icon' => $config['icon'] ?? '✓',
                'approvedAt' => $v->getApprovedAt()?->format('c'),
                'expiresAt' => $v->getExpiresAt()?->format('c'),
            ];
        }, $verifications);
    }

    /**
     * Vérifier si un utilisateur est certifié pour la catégorie d'une annonce
     * Utilisé pour l'affichage du badge contextuel
     */
    public function isUserCertifiedForCategory(User $user, string $category, ?string $subcategory = null): bool
    {
        $result = $this->canUserPublish($user, $category, $subcategory);
        return $result['canPublish'] && in_array($result['status'], ['APPROVED', 'OPTIONAL', null]);
    }

    /**
     * Parse un array PostgreSQL en array PHP
     */
    private function parsePostgresArray(?string $pgArray): array
    {
        if (!$pgArray || $pgArray === '{}') {
            return [];
        }

        // Enlever les accolades
        $pgArray = trim($pgArray, '{}');
        if (empty($pgArray)) {
            return [];
        }

        // Séparer par virgule
        return array_map('trim', explode(',', $pgArray));
    }
}
