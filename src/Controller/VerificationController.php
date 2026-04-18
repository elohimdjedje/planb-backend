<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\VerificationRequest;
use App\Repository\VerificationRequestRepository;
use App\Service\NotificationManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/verification')]
class VerificationController extends AbstractController
{
    private const MAX_ATTEMPTS = 3;

    private function getUploadDir(): string
    {
        return $this->getParameter('kernel.project_dir') . '/private/verification_docs';
    }

    public function __construct(
        private EntityManagerInterface $entityManager,
        private VerificationRequestRepository $verificationRepo,
        private NotificationManagerService $notificationManager
    ) {
    }

    /**
     * Récupérer le statut de vérification de l'utilisateur connecté
     */
    #[Route('/status', name: 'verification_status', methods: ['GET'])]
    public function getStatus(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $latestRequest = $this->verificationRepo->findLatestByUser($user);
        $attemptCount = $this->verificationRepo->countAttemptsByUser($user);

        return $this->json([
            'isVerified' => $user->isIdentityVerified(),
            'canPublish' => $user->canPublish(),
            'verificationBadges' => $user->getVerificationBadges(),
            'verificationCategory' => $user->getVerificationCategory(),
            'verifiedAt' => $user->getVerifiedAt()?->format('c'),
            'verificationStatus' => $user->getVerificationStatus(),
            'currentRequest' => $latestRequest ? [
                'id' => $latestRequest->getId(),
                'status' => $latestRequest->getStatus(),
                'category' => $latestRequest->getCategory(),
                'createdAt' => $latestRequest->getCreatedAt()->format('c'),
                'rejectionReason' => $latestRequest->getRejectionReason(),
                'attemptNumber' => $latestRequest->getAttemptNumber(),
            ] : null,
            'attemptsUsed' => $attemptCount,
            'maxAttempts' => self::MAX_ATTEMPTS,
            'canSubmit' => !$user->isIdentityVerified() 
                && $attemptCount < self::MAX_ATTEMPTS 
                && (!$latestRequest || !$latestRequest->isPending()),
        ]);
    }

    /**
     * Récupérer les documents requis selon la catégorie
     */
    #[Route('/required-documents/{category}', name: 'verification_required_docs', methods: ['GET'])]
    public function getRequiredDocuments(string $category): JsonResponse
    {
        $validCategories = [
            VerificationRequest::CATEGORY_PARTICULIER,
            VerificationRequest::CATEGORY_BAILLEUR,
            VerificationRequest::CATEGORY_VEHICULE,
            VerificationRequest::CATEGORY_HOTEL,
        ];

        if (!in_array($category, $validCategories)) {
            return $this->json(['error' => 'Catégorie invalide'], Response::HTTP_BAD_REQUEST);
        }

        $documents = VerificationRequest::getRequiredDocuments($category);

        $labels = [
            'cni_recto' => 'CNI ou Passeport (recto)',
            'cni_verso' => 'CNI ou Passeport (verso)',
            'selfie_with_id' => 'Selfie tenant la pièce d\'identité',
            'property_document' => 'Titre foncier / Bail notarié / Acte de propriété',
            'carte_grise' => 'Carte grise du véhicule',
            'cni_gerant' => 'CNI du gérant',
            'registre_commerce' => 'Registre de commerce',
            'licence_exploitation' => 'Licence d\'exploitation (optionnel)',
        ];

        $result = array_map(fn($doc) => [
            'key' => $doc,
            'label' => $labels[$doc] ?? $doc,
            'required' => $doc !== 'licence_exploitation',
        ], $documents);

        return $this->json([
            'category' => $category,
            'documents' => $result,
        ]);
    }

    /**
     * Soumettre une demande de vérification avec upload de documents
     */
    #[Route('/submit', name: 'verification_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        // Déjà vérifié ?
        if ($user->isIdentityVerified()) {
            return $this->json(['error' => 'Vous êtes déjà vérifié'], Response::HTTP_BAD_REQUEST);
        }

        // Demande en cours ?
        $pendingRequest = $this->verificationRepo->findPendingByUser($user);
        if ($pendingRequest) {
            return $this->json([
                'error' => 'Vous avez déjà une demande en cours d\'examen',
                'requestId' => $pendingRequest->getId(),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Nombre max de tentatives
        $attemptCount = $this->verificationRepo->countAttemptsByUser($user);
        if ($attemptCount >= self::MAX_ATTEMPTS) {
            return $this->json([
                'error' => 'Nombre maximum de tentatives atteint (3). Contactez le support.',
            ], Response::HTTP_FORBIDDEN);
        }

        $category = $request->request->get('category');
        $validCategories = [
            VerificationRequest::CATEGORY_PARTICULIER,
            VerificationRequest::CATEGORY_BAILLEUR,
            VerificationRequest::CATEGORY_VEHICULE,
            VerificationRequest::CATEGORY_HOTEL,
        ];

        if (!$category || !in_array($category, $validCategories)) {
            return $this->json(['error' => 'Catégorie invalide'], Response::HTTP_BAD_REQUEST);
        }

        // Récupérer les documents requis
        $requiredDocs = VerificationRequest::getRequiredDocuments($category);
        $uploadedFiles = $request->files->all();
        $documentPaths = [];

        // Créer le dossier privé
        $uploadDir = $this->getUploadDir() . '/' . $user->getId();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0700, true);
        }

        // Valider et stocker chaque document
        foreach ($requiredDocs as $docKey) {
            $file = $uploadedFiles[$docKey] ?? null;
            $isOptional = ($docKey === 'licence_exploitation');

            if (!$file && !$isOptional) {
                // Nettoyer les fichiers déjà uploadés
                $this->cleanupFiles($documentPaths);
                return $this->json([
                    'error' => "Le document '{$docKey}' est requis",
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($file) {
                // Valider type MIME
                $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf'];
                if (!in_array($file->getMimeType(), $allowedMimes)) {
                    $this->cleanupFiles($documentPaths);
                    return $this->json([
                        'error' => "Format non accepté pour '{$docKey}'. Formats acceptés : JPG, PNG, WebP, PDF",
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Valider taille (max 10MB)
                if ($file->getSize() > 10 * 1024 * 1024) {
                    $this->cleanupFiles($documentPaths);
                    return $this->json([
                        'error' => "Le fichier '{$docKey}' est trop volumineux (max 10MB)",
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Stocker le fichier avec un nom unique
                $fileName = $docKey . '_' . uniqid() . '_' . time() . '.' . $file->guessExtension();
                $file->move($uploadDir, $fileName);
                $documentPaths[$docKey] = $uploadDir . '/' . $fileName;
            }
        }

        // Créer la demande de vérification
        $verificationRequest = new VerificationRequest();
        $verificationRequest->setUser($user);
        $verificationRequest->setCategory($category);
        $verificationRequest->setDocuments($documentPaths);
        $verificationRequest->setAttemptNumber($attemptCount + 1);
        $verificationRequest->setBadgeType(VerificationRequest::getBadgeForCategory($category));

        $this->entityManager->persist($verificationRequest);

        // Mettre à jour le statut de l'utilisateur
        $user->setVerificationStatus('pending');
        $user->setVerificationCategory($category);

        $this->entityManager->flush();

        // Notification à l'utilisateur
        $this->notificationManager->createNotification(
            $user,
            'verification_submitted',
            '📄 Documents reçus',
            'Vos documents ont bien été reçus. Notre équipe les examine sous 24 à 72h.',
            ['requestId' => $verificationRequest->getId(), 'category' => $category],
            'medium'
        );

        return $this->json([
            'success' => true,
            'message' => 'Votre demande de vérification a été soumise avec succès.',
            'requestId' => $verificationRequest->getId(),
            'estimatedReview' => '24-72h',
        ]);
    }

    // ========== ENDPOINTS ADMIN ==========

    /**
     * Liste des demandes de vérification (admin)
     */
    #[Route('/admin/requests', name: 'verification_admin_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminListRequests(Request $request): JsonResponse
    {

        $status = $request->query->get('status', 'pending');
        
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('v')
            ->from(VerificationRequest::class, 'v')
            ->join('v.user', 'u');

        if ($status !== 'all') {
            $qb->where('v.status = :status')
                ->setParameter('status', $status);
        }

        $qb->orderBy('v.createdAt', $status === 'pending' ? 'ASC' : 'DESC');

        $requests = $qb->getQuery()->getResult();

        $data = array_map(function (VerificationRequest $vr) {
            $u = $vr->getUser();
            return [
                'id' => $vr->getId(),
                'user' => [
                    'id' => $u->getId(),
                    'firstName' => $u->getFirstName(),
                    'lastName' => $u->getLastName(),
                    'email' => $u->getEmail(),
                    'profilePicture' => $u->getProfilePicture(),
                    'phone' => $u->getPhone(),
                ],
                'category' => $vr->getCategory(),
                'status' => $vr->getStatus(),
                'badgeType' => $vr->getBadgeType(),
                'attemptNumber' => $vr->getAttemptNumber(),
                'documentsCount' => count($vr->getDocuments()),
                'createdAt' => $vr->getCreatedAt()->format('c'),
                'reviewedAt' => $vr->getReviewedAt()?->format('c'),
                'rejectionReason' => $vr->getRejectionReason(),
            ];
        }, $requests);

        $stats = $this->verificationRepo->getStats();

        return $this->json([
            'requests' => $data,
            'stats' => $stats,
        ]);
    }

    /**
     * Voir les documents d'une demande (admin)
     */
    #[Route('/admin/requests/{id}/documents', name: 'verification_admin_documents', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminViewDocuments(int $id): JsonResponse
    {

        $vr = $this->verificationRepo->find($id);
        if (!$vr) {
            return $this->json(['error' => 'Demande introuvable'], Response::HTTP_NOT_FOUND);
        }

        // Retourner les documents en base64 pour l'affichage admin
        $documents = [];
        foreach ($vr->getDocuments() as $key => $filePath) {
            if (file_exists($filePath)) {
                $mimeType = mime_content_type($filePath);
                $content = base64_encode(file_get_contents($filePath));
                $documents[] = [
                    'key' => $key,
                    'mimeType' => $mimeType,
                    'data' => "data:{$mimeType};base64,{$content}",
                    'size' => filesize($filePath),
                ];
            } else {
                $documents[] = [
                    'key' => $key,
                    'error' => 'Fichier non trouvé',
                ];
            }
        }

        return $this->json([
            'requestId' => $vr->getId(),
            'category' => $vr->getCategory(),
            'documents' => $documents,
        ]);
    }

    /**
     * Certifier un utilisateur (admin)
     */
    #[Route('/admin/requests/{id}/approve', name: 'verification_admin_approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminApprove(int $id): JsonResponse
    {
        $admin = $this->getUser();

        $vr = $this->verificationRepo->find($id);
        if (!$vr) {
            return $this->json(['error' => 'Demande introuvable'], Response::HTTP_NOT_FOUND);
        }

        if (!$vr->isPending()) {
            return $this->json(['error' => 'Cette demande a déjà été traitée'], Response::HTTP_BAD_REQUEST);
        }

        $targetUser = $vr->getUser();
        $badgeType = $vr->getBadgeType() ?? VerificationRequest::getBadgeForCategory($vr->getCategory());

        // Mettre à jour la demande
        $vr->setStatus(VerificationRequest::STATUS_APPROVED);
        $vr->setReviewedAt(new \DateTime());
        $vr->setReviewedBy($admin->getId());
        $vr->setAuditLog("Vérifié le " . (new \DateTime())->format('d/m/Y H:i') . " par admin #{$admin->getId()} ({$admin->getFullName()})");

        // Mettre à jour l'utilisateur
        $targetUser->setIsVerified(true);
        $targetUser->addVerificationBadge($badgeType);
        $targetUser->setVerificationStatus('approved');
        $targetUser->setVerifiedAt(new \DateTime());

        // Supprimer les fichiers de documents
        $this->cleanupFiles($vr->getDocuments());
        $vr->setDocuments([]);

        $this->entityManager->flush();

        // Notification
        $badgeLabel = $this->getBadgeLabel($badgeType);
        $this->notificationManager->createNotification(
            $targetUser,
            'verification_approved',
            '✅ Compte vérifié !',
            "Félicitations ! Votre compte est maintenant vérifié ({$badgeLabel}). Vous pouvez publier vos annonces en toute confiance. 🎉",
            ['badgeType' => $badgeType, 'requestId' => $vr->getId()],
            'high'
        );

        return $this->json([
            'success' => true,
            'message' => "Utilisateur {$targetUser->getFullName()} certifié avec succès",
            'badge' => $badgeType,
        ]);
    }

    /**
     * Rejeter une demande (admin)
     */
    #[Route('/admin/requests/{id}/reject', name: 'verification_admin_reject', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminReject(int $id, Request $request): JsonResponse
    {
        $admin = $this->getUser();

        $vr = $this->verificationRepo->find($id);
        if (!$vr) {
            return $this->json(['error' => 'Demande introuvable'], Response::HTTP_NOT_FOUND);
        }

        if (!$vr->isPending()) {
            return $this->json(['error' => 'Cette demande a déjà été traitée'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? null;

        if (!$reason || strlen(trim($reason)) < 5) {
            return $this->json(['error' => 'Le motif de rejet est requis (min 5 caractères)'], Response::HTTP_BAD_REQUEST);
        }

        $targetUser = $vr->getUser();

        // Mettre à jour la demande
        $vr->setStatus(VerificationRequest::STATUS_REJECTED);
        $vr->setRejectionReason($reason);
        $vr->setReviewedAt(new \DateTime());
        $vr->setReviewedBy($admin->getId());
        $vr->setAuditLog("Rejeté le " . (new \DateTime())->format('d/m/Y H:i') . " par admin #{$admin->getId()} - Motif: {$reason}");

        // Mettre à jour le statut utilisateur
        $targetUser->setVerificationStatus('rejected');

        // Supprimer les fichiers de documents
        $this->cleanupFiles($vr->getDocuments());
        $vr->setDocuments([]);

        $this->entityManager->flush();

        // Notification
        $attemptsLeft = self::MAX_ATTEMPTS - $vr->getAttemptNumber();
        $this->notificationManager->createNotification(
            $targetUser,
            'verification_rejected',
            '❌ Demande de vérification refusée',
            "Votre demande a été refusée pour le motif suivant : {$reason}. " .
            ($attemptsLeft > 0 
                ? "Vous pouvez soumettre de nouveaux documents ({$attemptsLeft} tentative(s) restante(s))."
                : "Vous avez atteint le nombre maximum de tentatives. Contactez le support."),
            ['requestId' => $vr->getId(), 'reason' => $reason, 'attemptsLeft' => $attemptsLeft],
            'high'
        );

        return $this->json([
            'success' => true,
            'message' => "Demande de {$targetUser->getFullName()} rejetée",
            'attemptsLeft' => $attemptsLeft,
        ]);
    }

    /**
     * Certifier manuellement sans documents (admin - pour contacts personnels)
     */
    #[Route('/admin/certify-manual/{userId}', name: 'verification_admin_manual', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminCertifyManual(int $userId): JsonResponse
    {
        $admin = $this->getUser();

        $targetUser = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$targetUser) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($targetUser->isIdentityVerified()) {
            return $this->json(['error' => 'Cet utilisateur est déjà vérifié'], Response::HTTP_BAD_REQUEST);
        }

        // Créer un enregistrement d'audit
        $vr = new VerificationRequest();
        $vr->setUser($targetUser);
        $vr->setCategory(VerificationRequest::CATEGORY_MANUAL);
        $vr->setStatus(VerificationRequest::STATUS_APPROVED);
        $vr->setBadgeType(VerificationRequest::BADGE_MANUAL);
        $vr->setReviewedAt(new \DateTime());
        $vr->setReviewedBy($admin->getId());
        $vr->setAuditLog("Certifié manuellement le " . (new \DateTime())->format('d/m/Y H:i') . " par admin #{$admin->getId()} ({$admin->getFullName()})");
        $this->entityManager->persist($vr);

        // Mettre à jour l'utilisateur
        $targetUser->setIsVerified(true);
        $targetUser->addVerificationBadge(VerificationRequest::BADGE_MANUAL);
        $targetUser->setVerificationStatus('approved');
        $targetUser->setVerificationCategory(VerificationRequest::CATEGORY_MANUAL);
        $targetUser->setVerifiedAt(new \DateTime());

        $this->entityManager->flush();

        // Notification
        $this->notificationManager->createNotification(
            $targetUser,
            'verification_approved',
            '⚡ Compte certifié !',
            'Félicitations ! Votre compte a été certifié par un administrateur. Vous pouvez maintenant publier vos annonces. 🎉',
            ['badgeType' => VerificationRequest::BADGE_MANUAL],
            'high'
        );

        return $this->json([
            'success' => true,
            'message' => "Utilisateur {$targetUser->getFullName()} certifié manuellement",
        ]);
    }

    /**
     * Retirer la certification d'un utilisateur (admin)
     */
    #[Route('/admin/revoke/{userId}', name: 'verification_admin_revoke', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminRevokeCertification(int $userId, Request $request): JsonResponse
    {
        $admin = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Signalements reçus';

        $targetUser = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$targetUser) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        // Retirer toute vérification
        $targetUser->setIsVerified(false);
        $targetUser->setVerificationBadges([]);
        $targetUser->setVerificationStatus('revoked');
        $targetUser->setVerifiedAt(null);

        $this->entityManager->flush();

        // Notification
        $this->notificationManager->createNotification(
            $targetUser,
            'verification_revoked',
            '⚠️ Certification retirée',
            "Votre certification a été retirée pour le motif suivant : {$reason}. Contactez le support pour plus d'informations.",
            ['reason' => $reason],
            'urgent'
        );

        return $this->json([
            'success' => true,
            'message' => "Certification de {$targetUser->getFullName()} retirée",
        ]);
    }

    // ========== HELPERS ==========

    private function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    private function getBadgeLabel(string $badgeType): string
    {
        return match($badgeType) {
            VerificationRequest::BADGE_IDENTITY => 'Identité vérifiée',
            VerificationRequest::BADGE_BAILLEUR => 'Bailleur certifié',
            VerificationRequest::BADGE_VEHICULE => 'Vendeur auto certifié',
            VerificationRequest::BADGE_HOTEL => 'Établissement certifié',
            VerificationRequest::BADGE_MANUAL => 'Certifié manuellement',
            default => 'Vérifié',
        };
    }
}
