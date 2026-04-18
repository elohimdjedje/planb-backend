<?php

namespace App\Controller\Api;

use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Service\NotificationManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    private NotificationRepository $notificationRepository;
    private NotificationPreferenceRepository $preferenceRepository;
    private NotificationManagerService $notificationManager;
    private EntityManagerInterface $entityManager;

    public function __construct(
        NotificationRepository $notificationRepository,
        NotificationPreferenceRepository $preferenceRepository,
        NotificationManagerService $notificationManager,
        EntityManagerInterface $entityManager
    ) {
        $this->notificationRepository = $notificationRepository;
        $this->preferenceRepository = $preferenceRepository;
        $this->notificationManager = $notificationManager;
        $this->entityManager = $entityManager;
    }

    /**
     * Retourne l'utilisateur authentifié (type-safe).
     */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié');
        }
        return $user;
    }

    /**
     * Récupère la liste des notifications de l'utilisateur connecté
     */
    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $status = $request->query->get('status'); // 'unread', 'read', 'archived' ou null (tous)
        $limit = (int) $request->query->get('limit', 50);

        $notifications = $this->notificationRepository->findByUser($user, $status, $limit);

        return $this->json([
            'success' => true,
            'data' => array_map(function (Notification $notif) {
                return [
                    'id' => $notif->getId(),
                    'type' => $notif->getType(),
                    'title' => $notif->getTitle(),
                    'message' => $notif->getMessage(),
                    'data' => $notif->getData(),
                    'priority' => $notif->getPriority(),
                    'status' => $notif->getStatus(),
                    'createdAt' => $notif->getCreatedAt()->format('c'),
                    'readAt' => $notif->getReadAt()?->format('c'),
                    'isRead' => $notif->isRead(),
                ];
            }, $notifications)
        ]);
    }

    /**
     * Compte les notifications non lues
     */
    #[Route('/unread-count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        $user = $this->requireUser();
        $count = $this->notificationRepository->countUnread($user);

        return $this->json([
            'success' => true,
            'count' => $count
        ]);
    }

    /**
     * Marque une notification comme lue
     */
    #[Route('/{id}/read', name: 'api_notifications_mark_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markAsRead(int $id): JsonResponse
    {
        $user = $this->requireUser();
        $notification = $this->notificationRepository->find($id);

        if (!$notification || $notification->getUser() !== $user) {
            return $this->json([
                'success' => false,
                'message' => 'Notification introuvable'
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$notification->isRead()) {
            $notification->markAsRead();
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => true,
            'message' => 'Notification marquée comme lue'
        ]);
    }

    /**
     * Marque toutes les notifications comme lues
     */
    #[Route('/read-all', name: 'api_notifications_mark_all_read', methods: ['POST'])]
    public function markAllAsRead(): JsonResponse
    {
        $user = $this->requireUser();
        $count = $this->notificationRepository->markAllAsRead($user);

        return $this->json([
            'success' => true,
            'message' => "{$count} notifications marquées comme lues",
            'count' => $count
        ]);
    }

    /**
     * Supprime une notification
     */
    #[Route('/{id}', name: 'api_notifications_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->requireUser();
        $notification = $this->notificationRepository->find($id);

        if (!$notification || $notification->getUser() !== $user) {
            return $this->json([
                'success' => false,
                'message' => 'Notification introuvable'
            ], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($notification);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Notification supprimée'
        ]);
    }

    /**
     * Archive une notification
     */
    #[Route('/{id}/archive', name: 'api_notifications_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(int $id): JsonResponse
    {
        $user = $this->requireUser();
        $notification = $this->notificationRepository->find($id);

        if (!$notification || $notification->getUser() !== $user) {
            return $this->json([
                'success' => false,
                'message' => 'Notification introuvable'
            ], Response::HTTP_NOT_FOUND);
        }

        $notification->setStatus('archived');
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Notification archivée'
        ]);
    }

    /**
     * Récupère les préférences de notification de l'utilisateur
     */
    #[Route('/preferences', name: 'api_notifications_preferences_get', methods: ['GET'])]
    public function getPreferences(): JsonResponse
    {
        $user = $this->requireUser();
        $prefs = $this->notificationManager->getOrCreatePreferences($user);

        return $this->json([
            'success' => true,
            'data' => [
                'favoritesRemoved' => $prefs->isFavoritesRemoved(),
                'listingExpired' => $prefs->isListingExpired(),
                'subscriptionExpiring' => $prefs->isSubscriptionExpiring(),
                'reviewReceived' => $prefs->isReviewReceived(),
                'reviewNegativeOnly' => $prefs->isReviewNegativeOnly(),
                'emailEnabled' => $prefs->isEmailEnabled(),
                'pushEnabled' => $prefs->isPushEnabled(),
                'emailFrequency' => $prefs->getEmailFrequency(),
                'doNotDisturbStart' => $prefs->getDoNotDisturbStart()?->format('H:i'),
                'doNotDisturbEnd' => $prefs->getDoNotDisturbEnd()?->format('H:i')
            ]
        ]);
    }

    /**
     * Met à jour les préférences de notification
     */
    #[Route('/preferences', name: 'api_notifications_preferences_update', methods: ['PUT', 'PATCH'])]
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $prefs = $this->notificationManager->getOrCreatePreferences($user);
        $data = json_decode($request->getContent(), true);

        if (isset($data['favoritesRemoved'])) {
            $prefs->setFavoritesRemoved((bool) $data['favoritesRemoved']);
        }
        if (isset($data['listingExpired'])) {
            $prefs->setListingExpired((bool) $data['listingExpired']);
        }
        if (isset($data['subscriptionExpiring'])) {
            $prefs->setSubscriptionExpiring((bool) $data['subscriptionExpiring']);
        }
        if (isset($data['reviewReceived'])) {
            $prefs->setReviewReceived((bool) $data['reviewReceived']);
        }
        if (isset($data['reviewNegativeOnly'])) {
            $prefs->setReviewNegativeOnly((bool) $data['reviewNegativeOnly']);
        }
        if (isset($data['emailEnabled'])) {
            $prefs->setEmailEnabled((bool) $data['emailEnabled']);
        }
        if (isset($data['pushEnabled'])) {
            $prefs->setPushEnabled((bool) $data['pushEnabled']);
        }
        if (isset($data['emailFrequency'])) {
            $validFrequencies = ['immediate', 'daily', 'weekly'];
            if (in_array($data['emailFrequency'], $validFrequencies)) {
                $prefs->setEmailFrequency($data['emailFrequency']);
            }
        }
        if (isset($data['doNotDisturbStart'])) {
            $prefs->setDoNotDisturbStart(
                $data['doNotDisturbStart'] ? new \DateTime($data['doNotDisturbStart']) : null
            );
        }
        if (isset($data['doNotDisturbEnd'])) {
            $prefs->setDoNotDisturbEnd(
                $data['doNotDisturbEnd'] ? new \DateTime($data['doNotDisturbEnd']) : null
            );
        }

        $prefs->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Préférences mises à jour'
        ]);
    }

    /**
     * Récupère les statistiques des notifications
     */
    #[Route('/stats', name: 'api_notifications_stats', methods: ['GET'])]
    public function getStats(): JsonResponse
    {
        $user = $this->requireUser();

        $unreadCount = $this->notificationRepository->countUnread($user);
        
        $allNotifications = $this->notificationRepository->findByUser($user, null, 100);
        
        $stats = [
            'unread' => $unreadCount,
            'total' => count($allNotifications),
            'byType' => [],
            'byPriority' => [
                'urgent' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ]
        ];

        foreach ($allNotifications as $notif) {
            $type = $notif->getType();
            if (!isset($stats['byType'][$type])) {
                $stats['byType'][$type] = 0;
            }
            $stats['byType'][$type]++;

            $priority = $notif->getPriority();
            if (isset($stats['byPriority'][$priority])) {
                $stats['byPriority'][$priority]++;
            }
        }

        return $this->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
