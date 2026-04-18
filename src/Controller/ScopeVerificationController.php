<?php

namespace App\Controller;

use App\Entity\ScopeVerification;
use App\Entity\UserDocument;
use App\Repository\ScopeVerificationRepository;
use App\Repository\UserDocumentRepository;
use App\Service\ScopeVerificationService;
use App\Service\ImageUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1')]
class ScopeVerificationController extends AbstractController
{
    public function __construct(
        private ScopeVerificationService $scopeVerificationService,
        private ScopeVerificationRepository $scopeVerificationRepository,
        private UserDocumentRepository $userDocumentRepository,
        private EntityManagerInterface $em,
        private ImageUploadService $imageUploadService
    ) {}

    /**
     * Récupérer les exigences pour une catégorie
     * GET /api/v1/categories/{category}/requirements?subcategory=xxx
     */
    #[Route('/categories/{category}/requirements', name: 'app_scope_requirements', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getRequirements(string $category, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $subcategory = $request->query->get('subcategory');

        $result = $this->scopeVerificationService->canUserPublish($user, $category, $subcategory);
        
        // Ajouter 'allowed' comme alias de 'canPublish' pour compatibilité frontend
        $result['allowed'] = $result['canPublish'];

        return $this->json($result);
    }

    /**
     * Vérifier si l'utilisateur peut publier dans une catégorie
     * GET /api/v1/categories/{category}/can-publish?subcategory=xxx
     */
    #[Route('/categories/{category}/can-publish', name: 'app_can_publish', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function canPublishInCategory(string $category, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $subcategory = $request->query->get('subcategory');

        $result = $this->scopeVerificationService->canUserPublish($user, $category, $subcategory);

        return $this->json([
            'canPublish' => $result['canPublish'],
            'requiredScope' => $result['requiredScope'] ?? null,
            'verificationStatus' => $result['status'] ?? null,
            'missingDocs' => $result['missingDocs'] ?? [],
            'verificationUrl' => !$result['canPublish'] ? '/verification-scope/' . ($result['requiredScope'] ?? '') : null,
        ]);
    }

    /**
     * Récupérer tous les scopes de l'utilisateur connecté
     * GET /api/v1/verifications/my-scopes
     */
    #[Route('/verifications/my-scopes', name: 'app_my_scopes', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getMyScopes(): JsonResponse
    {
        $user = $this->getUser();
        $scopes = $this->scopeVerificationService->getUserScopes($user);
        $approvedScopes = $this->scopeVerificationService->getApprovedScopes($user);

        return $this->json([
            'scopes' => $scopes,
            'approvedScopes' => $approvedScopes,
        ]);
    }

    /**
     * Récupérer tous les documents de l'utilisateur
     * GET /api/v1/verifications/my-documents
     */
    #[Route('/verifications/my-documents', name: 'app_my_documents', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getMyDocuments(): JsonResponse
    {
        $user = $this->getUser();
        $documents = $this->userDocumentRepository->findByUser($user);

        return $this->json([
            'documents' => array_map(fn($d) => $d->toArray(), $documents),
        ]);
    }

    /**
     * Upload un document
     * POST /api/v1/verifications/documents
     */
    #[Route('/verifications/documents', name: 'app_upload_document', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function uploadDocument(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $docType = $request->request->get('docType');
        $file = $request->files->get('file');

        if (!$docType) {
            return $this->json(['error' => 'Type de document requis'], 400);
        }

        if (!$file) {
            return $this->json(['error' => 'Fichier requis'], 400);
        }

        try {
            // Upload le fichier vers Cloudinary ou local
            $uploadResult = $this->imageUploadService->uploadDocument($file, 'verification-docs');
            $fileUrl = $uploadResult['url'];

            // Créer ou mettre à jour le document
            $existingDoc = $this->userDocumentRepository->findOneBy([
                'user' => $user,
                'docType' => $docType,
                'status' => UserDocument::STATUS_UPLOADED,
            ]);

            if ($existingDoc) {
                // Mettre à jour le document existant
                $existingDoc->setFileUrl($fileUrl);
                $existingDoc->setFileName($file->getClientOriginalName());
                $document = $existingDoc;
            } else {
                // Créer un nouveau document
                $document = new UserDocument();
                $document->setUser($user);
                $document->setDocType($docType);
                $document->setFileUrl($fileUrl);
                $document->setFileName($file->getClientOriginalName());
                $this->em->persist($document);
            }

            $this->em->flush();

            return $this->json([
                'success' => true,
                'document' => $document->toArray(),
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Soumettre une vérification pour un scope
     * POST /api/v1/verifications/{scopeKey}/submit
     */
    #[Route('/verifications/{scopeKey}/submit', name: 'app_submit_verification', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function submitVerification(string $scopeKey, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $documentIds = $data['documentIds'] ?? [];

        // Vérifier que le scope existe
        $scopeConfig = $this->scopeVerificationService->getScopeConfig($scopeKey);
        if (!$scopeConfig) {
            return $this->json(['error' => 'Scope inconnu'], 404);
        }

        // Vérifier que tous les documents requis sont fournis
        $requiredDocs = $scopeConfig['required_docs'] ?? [];
        $userValidDocs = $this->userDocumentRepository->getValidDocTypes($user);
        $userPendingDocs = array_map(
            fn($d) => $d->getDocType(),
            $this->userDocumentRepository->findPendingByUser($user)
        );
        $allUserDocs = array_merge($userValidDocs, $userPendingDocs);
        $missingDocs = array_diff($requiredDocs, $allUserDocs);

        if (!empty($missingDocs)) {
            return $this->json([
                'error' => 'Documents manquants',
                'missingDocs' => array_values($missingDocs),
            ], 400);
        }

        try {
            // Récupérer les IDs des documents pendants de l'utilisateur
            $pendingDocs = $this->userDocumentRepository->findPendingByUser($user);
            $docIds = array_map(fn($d) => $d->getId(), $pendingDocs);

            $verification = $this->scopeVerificationService->submitForScope($user, $scopeKey, $docIds);

            return $this->json([
                'success' => true,
                'verification' => $verification->toArray(),
                'message' => 'Documents soumis avec succès. Votre vérification est en cours de traitement.',
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Récupérer le statut d'une vérification
     * GET /api/v1/verifications/{scopeKey}/status
     */
    #[Route('/verifications/{scopeKey}/status', name: 'app_verification_status', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getVerificationStatus(string $scopeKey): JsonResponse
    {
        $user = $this->getUser();
        $verification = $this->scopeVerificationRepository->findByUserAndScope($user, $scopeKey);
        $scopeConfig = $this->scopeVerificationService->getScopeConfig($scopeKey);

        if (!$scopeConfig) {
            return $this->json(['error' => 'Scope inconnu'], 404);
        }

        return $this->json([
            'scopeKey' => $scopeKey,
            'scopeDisplayName' => $scopeConfig['display_name_fr'],
            'scopeIcon' => $scopeConfig['icon'],
            'requiredDocs' => $scopeConfig['required_docs'],
            'verification' => $verification?->toArray(),
            'status' => $verification?->getStatus() ?? 'NOT_STARTED',
        ]);
    }

    // ==================== ADMIN ENDPOINTS ====================

    /**
     * Liste des vérifications en attente (admin)
     * GET /api/v1/admin/verifications/pending
     */
    #[Route('/admin/verifications/pending', name: 'app_admin_pending_verifications', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function getPendingVerifications(): JsonResponse
    {
        $verifications = $this->scopeVerificationRepository->findAllPending();

        $result = array_map(function (ScopeVerification $v) {
            $config = $this->scopeVerificationService->getScopeConfig($v->getScopeKey());
            return [
                'id' => $v->getId(),
                'user' => [
                    'id' => $v->getUser()->getId(),
                    'firstName' => $v->getUser()->getFirstName(),
                    'lastName' => $v->getUser()->getLastName(),
                    'email' => $v->getUser()->getEmail(),
                ],
                'scopeKey' => $v->getScopeKey(),
                'scopeDisplayName' => $config['display_name_fr'] ?? $v->getScopeKey(),
                'scopeIcon' => $config['icon'] ?? '✓',
                'status' => $v->getStatus(),
                'submittedAt' => $v->getSubmittedAt()?->format('c'),
                'rejectionCount' => $v->getRejectionCount(),
                'documents' => array_map(fn($d) => [
                    'id' => $d->getId(),
                    'docType' => $d->getDocType(),
                    'fileName' => $d->getFileName(),
                    'fileUrl' => $d->getFileUrl(),
                    'status' => $d->getStatus(),
                ], $v->getDocuments()->toArray()),
            ];
        }, $verifications);

        return $this->json([
            'verifications' => $result,
            'count' => count($result),
        ]);
    }

    /**
     * Approuver une vérification (admin)
     * POST /api/v1/admin/verifications/{id}/approve
     */
    #[Route('/admin/verifications/{id}/approve', name: 'app_admin_approve_verification', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approveVerification(int $id): JsonResponse
    {
        $admin = $this->getUser();
        $verification = $this->scopeVerificationRepository->find($id);

        if (!$verification) {
            return $this->json(['error' => 'Vérification introuvable'], 404);
        }

        if ($verification->getStatus() !== ScopeVerification::STATUS_PENDING) {
            return $this->json(['error' => 'Cette vérification n\'est pas en attente'], 400);
        }

        $this->scopeVerificationService->approveVerification($verification, $admin);

        return $this->json([
            'success' => true,
            'message' => 'Vérification approuvée',
            'verification' => $verification->toArray(),
        ]);
    }

    /**
     * Rejeter une vérification (admin)
     * POST /api/v1/admin/verifications/{id}/reject
     */
    #[Route('/admin/verifications/{id}/reject', name: 'app_admin_reject_verification', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rejectVerification(int $id, Request $request): JsonResponse
    {
        $admin = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $reason = $data['reason'] ?? '';
        $rejectedDocIds = $data['rejectedDocIds'] ?? [];

        if (empty($reason)) {
            return $this->json(['error' => 'Motif de rejet requis'], 400);
        }

        $verification = $this->scopeVerificationRepository->find($id);

        if (!$verification) {
            return $this->json(['error' => 'Vérification introuvable'], 404);
        }

        if ($verification->getStatus() !== ScopeVerification::STATUS_PENDING) {
            return $this->json(['error' => 'Cette vérification n\'est pas en attente'], 400);
        }

        $this->scopeVerificationService->rejectVerification($verification, $admin, $reason, $rejectedDocIds);

        return $this->json([
            'success' => true,
            'message' => 'Vérification rejetée',
            'verification' => $verification->toArray(),
        ]);
    }

    /**
     * Stats des vérifications (admin)
     * GET /api/v1/admin/verifications/stats
     */
    #[Route('/admin/verifications/stats', name: 'app_admin_verification_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function getVerificationStats(): JsonResponse
    {
        $stats = $this->scopeVerificationRepository->getVerificationStats();

        return $this->json(['stats' => $stats]);
    }
}
