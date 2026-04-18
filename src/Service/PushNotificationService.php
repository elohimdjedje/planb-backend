<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
// Note: minishlink/web-push sera installé via composer
// use Minishlink\WebPush\WebPush;
// use Minishlink\WebPush\Subscription;
use Psr\Log\LoggerInterface;

/**
 * Service pour envoyer des notifications push
 * Supporte Web Push API (navigateur) et FCM (mobile)
 */
class PushNotificationService
{
    private $webPush = null; // \Minishlink\WebPush\WebPush (initialisé à la demande)
    private string $vapidPublicKey;
    private string $vapidPrivateKey;
    private string $vapidSubject;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PushSubscriptionRepository $pushSubscriptionRepository,
        private LoggerInterface $logger
    ) {
        // Clés VAPID pour Web Push API
        $this->vapidPublicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? '';
        $this->vapidPrivateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? '';
        $this->vapidSubject = $_ENV['VAPID_SUBJECT'] ?? $_ENV['APP_URL'] ?? 'mailto:admin@planb.com';

        // WebPush sera initialisé à la demande dans sendWebPush()
        // pour éviter les erreurs si la librairie n'est pas installée
    }

    /**
     * Envoyer une notification push à un utilisateur
     * 
     * @param User $user Utilisateur destinataire
     * @param Notification $notification Notification à envoyer
     * @return array Résultats (succès/échecs)
     */
    public function sendToUser(User $user, Notification $notification): array
    {
        $subscriptions = $this->pushSubscriptionRepository->findActiveByUser($user);
        
        if (empty($subscriptions)) {
            $this->logger->info('No push subscriptions found for user', [
                'user_id' => $user->getId()
            ]);
            return ['success' => 0, 'failed' => 0];
        }

        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($subscriptions as $subscription) {
            try {
                if ($subscription->getPlatform() === 'web') {
                    $result = $this->sendWebPush($subscription, $notification);
                } elseif (in_array($subscription->getPlatform(), ['ios', 'android'])) {
                    $result = $this->sendMobilePush($subscription, $notification);
                } else {
                    continue;
                }

                if ($result['success']) {
                    $results['success']++;
                    $subscription->setLastUsedAt(new \DateTime());
                    $this->entityManager->flush();
                } else {
                    $results['failed']++;
                    $results['errors'][] = $result['error'] ?? 'Unknown error';
                    
                    // Désactiver la souscription si elle est invalide
                    if (isset($result['invalid']) && $result['invalid']) {
                        $subscription->setIsActive(false);
                        $this->entityManager->flush();
                    }
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = $e->getMessage();
                $this->logger->error('Error sending push notification', [
                    'user_id' => $user->getId(),
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Envoyer une notification Web Push (navigateur)
     */
    private function sendWebPush(PushSubscription $subscription, Notification $notification): array
    {
        if (empty($this->vapidPublicKey) || empty($this->vapidPrivateKey)) {
            return [
                'success' => false,
                'error' => 'VAPID keys not configured'
            ];
        }

        // Vérifier si la librairie est disponible
        if (!class_exists('\Minishlink\WebPush\WebPush')) {
            $this->logger->warning('WebPush library not installed. Install with: composer require minishlink/web-push');
            return [
                'success' => false,
                'error' => 'WebPush library not installed. Run: composer require minishlink/web-push'
            ];
        }

        try {
            // Initialiser WebPush si pas déjà fait
            if (!$this->webPush) {
                $this->webPush = new \Minishlink\WebPush\WebPush([
                    'VAPID' => [
                        'subject' => $this->vapidSubject,
                        'publicKey' => $this->vapidPublicKey,
                        'privateKey' => $this->vapidPrivateKey,
                    ],
                ]);
            }

            $pushSubscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => $subscription->getEndpoint(),
                'keys' => [
                    'p256dh' => $subscription->getP256dh(),
                    'auth' => $subscription->getAuth(),
                ],
            ]);

            $payload = json_encode([
                'title' => $notification->getTitle(),
                'body' => $notification->getMessage(),
                'icon' => '/icon-192x192.png',
                'badge' => '/badge-72x72.png',
                'data' => [
                    'notificationId' => $notification->getId(),
                    'type' => $notification->getType(),
                    'url' => $this->getNotificationUrl($notification),
                    'data' => $notification->getData()
                ],
                'requireInteraction' => $notification->getPriority() === 'urgent',
                'tag' => $notification->getType(),
            ]);

            $this->webPush->queueNotification($pushSubscription, $payload);
            
            // Envoyer toutes les notifications en file
            foreach ($this->webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    return ['success' => true];
                } else {
                    return [
                        'success' => false,
                        'error' => $report->getReason(),
                        'invalid' => $report->isSubscriptionExpired()
                    ];
                }
            }

            return ['success' => true];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Envoyer une notification mobile (FCM/APNS)
     */
    private function sendMobilePush(PushSubscription $subscription, Notification $notification): array
    {
        $deviceToken = $subscription->getDeviceToken();
        
        if (!$deviceToken) {
            return [
                'success' => false,
                'error' => 'Device token missing'
            ];
        }

        // Utiliser FCM pour Android et iOS
        $fcmServerKey = $_ENV['FCM_SERVER_KEY'] ?? '';
        
        if (empty($fcmServerKey)) {
            return [
                'success' => false,
                'error' => 'FCM server key not configured'
            ];
        }

        try {
            $url = 'https://fcm.googleapis.com/fcm/send';
            
            $payload = [
                'to' => $deviceToken,
                'notification' => [
                    'title' => $notification->getTitle(),
                    'body' => $notification->getMessage(),
                    'sound' => 'default',
                    'badge' => '1',
                ],
                'data' => [
                    'notificationId' => $notification->getId(),
                    'type' => $notification->getType(),
                    'url' => $this->getNotificationUrl($notification),
                    'data' => $notification->getData()
                ],
                'priority' => $notification->getPriority() === 'urgent' ? 'high' : 'normal',
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Authorization: key=' . $fcmServerKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['success']) && $result['success'] > 0) {
                    return ['success' => true];
                } else {
                    return [
                        'success' => false,
                        'error' => $result['results'][0]['error'] ?? 'Unknown FCM error',
                        'invalid' => isset($result['results'][0]['error']) && 
                                   strpos($result['results'][0]['error'], 'InvalidRegistration') !== false
                    ];
                }
            }

            return [
                'success' => false,
                'error' => "HTTP {$httpCode}"
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtenir l'URL de redirection pour une notification
     */
    private function getNotificationUrl(Notification $notification): string
    {
        $data = $notification->getData() ?? [];
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:5173';

        switch ($notification->getType()) {
            case 'new_message':
                return $baseUrl . '/messages/' . ($data['conversationId'] ?? '');
            case 'review_received':
                return $baseUrl . '/profile/reviews';
            case 'listing_expired':
            case 'listing_expiring_soon':
                return $baseUrl . '/listings/' . ($data['listingId'] ?? '');
            case 'subscription_expiring':
            case 'subscription_expired':
                $webBase = $_ENV['FRONTEND_URL'] ?? $baseUrl;

                return rtrim($webBase, '/') . '/upgrade';
            default:
                return $baseUrl . '/notifications';
        }
    }

    /**
     * Envoyer une notification push à plusieurs utilisateurs
     */
    public function sendToUsers(array $users, Notification $notification): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'total' => count($users)
        ];

        foreach ($users as $user) {
            $result = $this->sendToUser($user, $notification);
            $results['success'] += $result['success'];
            $results['failed'] += $result['failed'];
        }

        return $results;
    }
}

