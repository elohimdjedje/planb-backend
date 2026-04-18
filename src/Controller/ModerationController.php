<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Entity\Report;
use App\Entity\User;
use App\Repository\ListingRepository;
use App\Repository\ModerationActionRepository;
use App\Repository\ReportRepository;
use App\Repository\UserRepository;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/moderation')]
#[IsGranted('ROLE_ADMIN')]
class ModerationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ModerationService $moderationService,
        private ReportRepository $reportRepository,
        private ModerationActionRepository $moderationActionRepository,
        private ListingRepository $listingRepository,
        private UserRepository $userRepository
    ) {
    }

    /**
     * Liste des signalements en attente
     */
    #[Route('/reports/pending', name: 'moderation_reports_pending', methods: ['GET'])]
    public function pendingReports(Request $request): JsonResponse
    {
        $limit = min($request->query->getInt('limit', 50), 100);
        $offset = $request->query->getInt('offset', 0);

        $reports = $this->reportRepository->findPending();
        $total = count($reports);
        $reports = array_slice($reports, $offset, $limit);

        $data = array_map(function (Report $report) {
            $listing = $report->getListing();
            $reporter = $report->getReporter();
            $reportCount = $this->reportRepository->countByListing($listing);

            return [
                'id' => $report->getId(),
                'reason' => $report->getReason(),
                'reasonLabel' => $report->getReasonLabel(),
                'description' => $report->getDescription(),
                'status' => $report->getStatus(),
                'createdAt' => $report->getCreatedAt()?->format('c'),
                'listing' => [
                    'id' => $listing->getId(),
                    'title' => $listing->getTitle(),
                    'price' => $listing->getPrice(),
                    'currency' => $listing->getCurrency(),
                    'category' => $listing->getCategory(),
                    'status' => $listing->getStatus(),
                    'viewsCount' => $listing->getViewsCount(),
                    'user' => [
                        'id' => $listing->getUser()->getId(),
                        'email' => $listing->getUser()->getEmail(),
                        'fullName' => $listing->getUser()->getFirstName() . ' ' . $listing->getUser()->getLastName(),
                    ]
                ],
                'reporter' => $reporter ? [
                    'id' => $reporter->getId(),
                    'email' => $reporter->getEmail(),
                ] : null,
                'reportCount' => $reportCount, // Nombre total de signalements pour cette annonce
            ];
        }, $reports);

        return $this->json([
            'reports' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Détail d'un signalement
     */
    #[Route('/reports/{id}', name: 'moderation_report_detail', methods: ['GET'])]
    public function reportDetail(int $id): JsonResponse
    {
        $report = $this->reportRepository->find($id);
        
        if (!$report) {
            return $this->json(['error' => 'Signalement introuvable'], Response::HTTP_NOT_FOUND);
        }

        $listing = $report->getListing();
        $allReports = $this->reportRepository->findBy(['listing' => $listing], ['createdAt' => 'DESC']);

        return $this->json([
            'report' => [
                'id' => $report->getId(),
                'reason' => $report->getReason(),
                'reasonLabel' => $report->getReasonLabel(),
                'description' => $report->getDescription(),
                'status' => $report->getStatus(),
                'adminNotes' => $report->getAdminNotes(),
                'createdAt' => $report->getCreatedAt()?->format('c'),
                'reviewedAt' => $report->getReviewedAt()?->format('c'),
                'reporter' => $report->getReporter() ? [
                    'id' => $report->getReporter()->getId(),
                    'email' => $report->getReporter()->getEmail(),
                ] : null,
            ],
            'listing' => [
                'id' => $listing->getId(),
                'title' => $listing->getTitle(),
                'description' => $listing->getDescription(),
                'price' => $listing->getPrice(),
                'currency' => $listing->getCurrency(),
                'category' => $listing->getCategory(),
                'status' => $listing->getStatus(),
                'user' => [
                    'id' => $listing->getUser()->getId(),
                    'email' => $listing->getUser()->getEmail(),
                    'fullName' => $listing->getUser()->getFirstName() . ' ' . $listing->getUser()->getLastName(),
                    'warningsCount' => $listing->getUser()->getWarningsCount() ?? 0,
                ]
            ],
            'allReports' => array_map(function (Report $r) {
                return [
                    'id' => $r->getId(),
                    'reason' => $r->getReason(),
                    'reasonLabel' => $r->getReasonLabel(),
                    'createdAt' => $r->getCreatedAt()?->format('c'),
                ];
            }, $allReports)
        ]);
    }

    /**
     * Traiter un signalement
     */
    #[Route('/reports/{id}/process', name: 'moderation_report_process', methods: ['POST'])]
    public function processReport(int $id, Request $request): JsonResponse
    {
        /** @var User $moderator */
        $moderator = $this->getUser();
        $report = $this->reportRepository->find($id);

        if (!$report) {
            return $this->json(['error' => 'Signalement introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($report->getStatus() !== 'pending') {
            return $this->json(['error' => 'Ce signalement a déjà été traité'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $action = $data['action'] ?? null; // hide, delete, warn, ban, approve
        $reason = $data['reason'] ?? 'Aucune raison spécifiée';
        $notes = $data['notes'] ?? null;

        if (!in_array($action, ['hide', 'delete', 'warn', 'ban', 'approve'])) {
            return $this->json(['error' => 'Action invalide'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ($action === 'approve') {
                $moderationAction = $this->moderationService->approveReport($report, $moderator, $notes);
            } else {
                $moderationAction = $this->moderationService->processReport($report, $moderator, $action, $reason, $notes);
            }

            return $this->json([
                'message' => 'Signalement traité avec succès',
                'action' => [
                    'id' => $moderationAction->getId(),
                    'type' => $moderationAction->getActionType(),
                    'reason' => $moderationAction->getReason(),
                    'createdAt' => $moderationAction->getCreatedAt()?->format('c'),
                ]
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Actions de modération directes (sans signalement)
     */
    #[Route('/listings/{id}/hide', name: 'moderation_listing_hide', methods: ['POST'])]
    public function hideListing(int $id, Request $request): JsonResponse
    {
        /** @var User $moderator */
        $moderator = $this->getUser();
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Aucune raison spécifiée';

        $action = $this->moderationService->hideListing($listing, $moderator, $reason);

        return $this->json([
            'message' => 'Annonce masquée',
            'action' => [
                'id' => $action->getId(),
                'type' => $action->getActionType(),
            ]
        ]);
    }

    #[Route('/listings/{id}/delete', name: 'moderation_listing_delete', methods: ['POST'])]
    public function deleteListing(int $id, Request $request): JsonResponse
    {
        /** @var User $moderator */
        $moderator = $this->getUser();
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Aucune raison spécifiée';

        $action = $this->moderationService->deleteListing($listing, $moderator, $reason);

        return $this->json([
            'message' => 'Annonce supprimée',
            'action' => [
                'id' => $action->getId(),
                'type' => $action->getActionType(),
            ]
        ]);
    }

    #[Route('/users/{id}/warn', name: 'moderation_user_warn', methods: ['POST'])]
    public function warnUser(int $id, Request $request): JsonResponse
    {
        /** @var User $moderator */
        $moderator = $this->getUser();
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Aucune raison spécifiée';

        $action = $this->moderationService->warnUser($user, $moderator, $reason);

        return $this->json([
            'message' => 'Utilisateur averti',
            'action' => [
                'id' => $action->getId(),
                'type' => $action->getActionType(),
            ],
            'user' => [
                'warningsCount' => $user->getWarningsCount(),
            ]
        ]);
    }

    #[Route('/users/{id}/suspend', name: 'moderation_user_suspend', methods: ['POST'])]
    public function suspendUser(int $id, Request $request): JsonResponse
    {
        /** @var User $moderator */
        $moderator = $this->getUser();
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Aucune raison spécifiée';
        $days = $data['days'] ?? 7;

        $action = $this->moderationService->suspendUser($user, $moderator, $reason, $days);

        return $this->json([
            'message' => 'Utilisateur suspendu',
            'action' => [
                'id' => $action->getId(),
                'type' => $action->getActionType(),
                'expiresAt' => $action->getExpiresAt()?->format('c'),
            ]
        ]);
    }

    #[Route('/users/{id}/ban', name: 'moderation_user_ban', methods: ['POST'])]
    public function banUser(int $id, Request $request): JsonResponse
    {
        /** @var User $moderator */
        $moderator = $this->getUser();
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Aucune raison spécifiée';
        $days = $data['days'] ?? null; // null = permanent

        $action = $this->moderationService->banUser($user, $moderator, $reason, null, $days);

        return $this->json([
            'message' => $days ? 'Utilisateur banni temporairement' : 'Utilisateur banni définitivement',
            'action' => [
                'id' => $action->getId(),
                'type' => $action->getActionType(),
                'expiresAt' => $action->getExpiresAt()?->format('c'),
            ]
        ]);
    }

    #[Route('/users/{id}/unban', name: 'moderation_user_unban', methods: ['POST'])]
    public function unbanUser(int $id, Request $request): JsonResponse
    {
        /** @var User $moderator */
        $moderator = $this->getUser();
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Compte réactivé';

        $action = $this->moderationService->unbanUser($user, $moderator, $reason);

        return $this->json([
            'message' => 'Utilisateur débanni',
            'action' => [
                'id' => $action->getId(),
                'type' => $action->getActionType(),
            ]
        ]);
    }

    /**
     * Historique de modération d'un utilisateur
     */
    #[Route('/users/{id}/history', name: 'moderation_user_history', methods: ['GET'])]
    public function userHistory(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $history = $this->moderationService->getUserModerationHistory($user);

        $data = array_map(function ($action) {
            return [
                'id' => $action->getId(),
                'actionType' => $action->getActionType(),
                'reason' => $action->getReason(),
                'notes' => $action->getNotes(),
                'moderator' => [
                    'id' => $action->getModerator()->getId(),
                    'email' => $action->getModerator()->getEmail(),
                ],
                'createdAt' => $action->getCreatedAt()?->format('c'),
                'expiresAt' => $action->getExpiresAt()?->format('c'),
            ];
        }, $history);

        return $this->json(['history' => $data]);
    }

    /**
     * Statistiques de modération
     */
    #[Route('/stats', name: 'moderation_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $stats = $this->moderationActionRepository->getModerationStats();

        return $this->json(['stats' => $stats]);
    }
}


