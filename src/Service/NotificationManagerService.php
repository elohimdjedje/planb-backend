<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Entity\Listing;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service centralisé pour créer et gérer les notifications
 */
class NotificationManagerService
{
    // Types de notifications
    public const TYPE_FAVORITE_UNAVAILABLE = 'favorite_unavailable';
    public const TYPE_LISTING_EXPIRED = 'listing_expired';
    public const TYPE_LISTING_EXPIRING_SOON = 'listing_expiring_soon';
    public const TYPE_SUBSCRIPTION_EXPIRING = 'subscription_expiring';
    public const TYPE_SUBSCRIPTION_EXPIRED = 'subscription_expired';
    public const TYPE_REVIEW_RECEIVED = 'review_received';
    public const TYPE_NEW_MESSAGE = 'new_message';
    public const TYPE_LISTING_PUBLISHED = 'listing_published';
    public const TYPE_WELCOME = 'welcome';

    // Priorités
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationRepository $notificationRepository,
        private NotificationPreferenceRepository $preferenceRepository,
        private NotificationService $notificationService,
        private PushNotificationService $pushNotificationService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Crée une notification en base de données
     */
    public function createNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        string $priority = self::PRIORITY_MEDIUM,
        ?\DateTimeInterface $expiresAt = null
    ): Notification {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setData($data);
        $notification->setPriority($priority);

        if ($expiresAt) {
            $notification->setExpiresAt($expiresAt);
        }

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        $this->logger->info('Notification created', [
            'userId' => $user->getId(),
            'type' => $type,
            'title' => $title
        ]);

        // Envoyer une notification push si les préférences le permettent
        $prefs = $this->getOrCreatePreferences($user);
        if ($prefs->isPushEnabled()) {
            try {
                $pushResult = $this->pushNotificationService->sendToUser($user, $notification);
                $this->logger->info('Push notification sent', [
                    'userId' => $user->getId(),
                    'notificationId' => $notification->getId(),
                    'success' => $pushResult['success'],
                    'failed' => $pushResult['failed']
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send push notification', [
                    'userId' => $user->getId(),
                    'notificationId' => $notification->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $notification;
    }

    /**
     * Vérifie si un utilisateur veut recevoir ce type de notification
     */
    public function shouldNotify(User $user, string $type): bool
    {
        $prefs = $this->getOrCreatePreferences($user);

        return match($type) {
            self::TYPE_FAVORITE_UNAVAILABLE => $prefs->isFavoritesRemoved(),
            self::TYPE_LISTING_EXPIRED,
            self::TYPE_LISTING_EXPIRING_SOON => $prefs->isListingExpired(),
            self::TYPE_SUBSCRIPTION_EXPIRING,
            self::TYPE_SUBSCRIPTION_EXPIRED => $prefs->isSubscriptionExpiring(),
            self::TYPE_REVIEW_RECEIVED => $prefs->isReviewReceived(),
            default => true
        };
    }

    /**
     * Récupère ou crée les préférences de notification d'un utilisateur
     */
    public function getOrCreatePreferences(User $user): NotificationPreference
    {
        $prefs = $this->preferenceRepository->findOneBy(['user' => $user]);

        if (!$prefs) {
            $prefs = new NotificationPreference();
            $prefs->setUser($user);
            $this->entityManager->persist($prefs);
            $this->entityManager->flush();
        }

        return $prefs;
    }

    // ========== NOTIFICATIONS FAVORIS ==========

    /**
     * Notifie tous les utilisateurs qui ont mis cette annonce en favori
     * que l'annonce n'est plus disponible
     */
    public function notifyFavoriteUnavailable(Listing $listing, string $reason = 'expired'): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $favorites = $qb->select('f')
            ->from('App\Entity\Favorite', 'f')
            ->join('f.user', 'u')
            ->where('f.listing = :listing')
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getResult();

        $count = 0;
        $reasonText = match($reason) {
            'sold' => 'vendue',
            'expired' => 'expirée',
            'deleted' => 'supprimée',
            default => 'plus disponible'
        };

        foreach ($favorites as $favorite) {
            $user = $favorite->getUser();
            
            if (!$this->shouldNotify($user, self::TYPE_FAVORITE_UNAVAILABLE)) {
                continue;
            }

            $this->createNotification(
                $user,
                self::TYPE_FAVORITE_UNAVAILABLE,
                '❤️ Favori indisponible',
                "L'annonce \"{$listing->getTitle()}\" que vous aviez en favori est maintenant {$reasonText}.",
                [
                    'listingId' => $listing->getId(),
                    'listingTitle' => $listing->getTitle(),
                    'reason' => $reason,
                    'category' => $listing->getCategory(),
                    'subcategory' => $listing->getSubcategory()
                ],
                self::PRIORITY_MEDIUM,
                new \DateTime('+30 days')
            );

            $count++;
        }

        $this->logger->info("Notified {$count} users about unavailable favorite", [
            'listingId' => $listing->getId(),
            'reason' => $reason
        ]);

        return $count;
    }

    // ========== NOTIFICATIONS ANNONCES ==========

    /**
     * Notifie un utilisateur FREE que son annonce a expiré
     */
    public function notifyListingExpired(Listing $listing): void
    {
        $user = $listing->getUser();
        
        if (!$this->shouldNotify($user, self::TYPE_LISTING_EXPIRED)) {
            return;
        }

        $this->createNotification(
            $user,
            self::TYPE_LISTING_EXPIRED,
            '⏰ Annonce expirée',
            "Votre annonce \"{$listing->getTitle()}\" a expiré. Renouvelez-la ou passez à PRO pour des durées plus longues !",
            [
                'listingId' => $listing->getId(),
                'listingTitle' => $listing->getTitle(),
                'category' => $listing->getCategory()
            ],
            self::PRIORITY_HIGH,
            new \DateTime('+14 days')
        );
    }

    /**
     * Notifie un utilisateur FREE que son annonce expire bientôt
     */
    public function notifyListingExpiringSoon(Listing $listing, int $daysRemaining): void
    {
        $user = $listing->getUser();
        
        if (!$this->shouldNotify($user, self::TYPE_LISTING_EXPIRING_SOON)) {
            return;
        }

        $priority = $daysRemaining <= 1 ? self::PRIORITY_URGENT : self::PRIORITY_HIGH;

        $this->createNotification(
            $user,
            self::TYPE_LISTING_EXPIRING_SOON,
            "⏳ Annonce expire dans {$daysRemaining} jour(s)",
            "Votre annonce \"{$listing->getTitle()}\" expire dans {$daysRemaining} jour(s). Pensez à la renouveler !",
            [
                'listingId' => $listing->getId(),
                'listingTitle' => $listing->getTitle(),
                'daysRemaining' => $daysRemaining
            ],
            $priority,
            new \DateTime('+7 days')
        );
    }

    // ========== NOTIFICATIONS ABONNEMENT PRO ==========

    /**
     * Notifie un utilisateur PRO que son abonnement expire bientôt
     */
    public function notifySubscriptionExpiring(User $user, int $daysRemaining): void
    {
        if (!$this->shouldNotify($user, self::TYPE_SUBSCRIPTION_EXPIRING)) {
            return;
        }

        // Vérifier si une notification similaire existe déjà pour cette période
        $existingNotifs = $this->notificationRepository->findByType($user, self::TYPE_SUBSCRIPTION_EXPIRING, 5);
        foreach ($existingNotifs as $notif) {
            $data = $notif->getData();
            if ($data && isset($data['daysRemaining']) && $data['daysRemaining'] === $daysRemaining) {
                // Notification déjà envoyée pour ce jour
                return;
            }
        }

        $priority = match(true) {
            $daysRemaining <= 3 => self::PRIORITY_URGENT,
            $daysRemaining <= 7 => self::PRIORITY_HIGH,
            default => self::PRIORITY_MEDIUM
        };

        $emoji = match(true) {
            $daysRemaining <= 3 => '🚨',
            $daysRemaining <= 7 => '⚠️',
            default => '📅'
        };

        $this->createNotification(
            $user,
            self::TYPE_SUBSCRIPTION_EXPIRING,
            "{$emoji} Abonnement PRO expire dans {$daysRemaining} jour(s)",
            "Votre abonnement PRO expire dans {$daysRemaining} jour(s). Renouvelez pour continuer à profiter des avantages exclusifs !",
            [
                'daysRemaining' => $daysRemaining,
                'expiresAt' => $user->getSubscriptionExpiresAt()?->format('c')
            ],
            $priority,
            new \DateTime('+7 days')
        );

        // Envoyer aussi par email si l'utilisateur l'a activé
        $prefs = $this->getOrCreatePreferences($user);
        if ($prefs->isEmailEnabled()) {
            $this->notificationService->notifySubscriptionExpiringSoon($user, $daysRemaining);
        }
    }

    /**
     * Notifie un utilisateur que son abonnement PRO a expiré
     */
    public function notifySubscriptionExpired(User $user): void
    {
        if (!$this->shouldNotify($user, self::TYPE_SUBSCRIPTION_EXPIRED)) {
            return;
        }

        // CRON + listener peuvent tous deux traiter l’expiration : un seul cycle notif / mail
        $already = $this->notificationRepository->findByType($user, self::TYPE_SUBSCRIPTION_EXPIRED, 1);
        $cutoff = new \DateTime('-24 hours');
        if ($already !== [] && $already[0]->getCreatedAt() > $cutoff) {
            return;
        }

        $this->createNotification(
            $user,
            self::TYPE_SUBSCRIPTION_EXPIRED,
            '🔴 Abonnement PRO expiré',
            'Votre abonnement PRO a expiré. Vos annonces seront limitées et certains avantages désactivés. Renouvelez maintenant !',
            [
                'accountType' => $user->getAccountType()
            ],
            self::PRIORITY_URGENT,
            new \DateTime('+30 days')
        );

        $prefs = $this->getOrCreatePreferences($user);
        $this->notificationService->notifySubscriptionExpired(
            $user,
            $prefs->isEmailEnabled(),
            true
        );
    }

    // ========== NOTIFICATIONS AVIS ==========

    /**
     * Notifie un vendeur qu'il a reçu un avis
     */
    public function notifyReviewReceived(
        User $seller, 
        User $reviewer, 
        int $rating, 
        string $comment,
        ?Listing $listing = null
    ): void {
        if (!$this->shouldNotify($seller, self::TYPE_REVIEW_RECEIVED)) {
            return;
        }

        $prefs = $this->getOrCreatePreferences($seller);
        
        // Si l'utilisateur ne veut que les avis négatifs et que c'est un avis positif
        if ($prefs->isReviewNegativeOnly() && $rating >= 4) {
            return;
        }

        $emoji = $rating >= 4 ? '⭐' : ($rating >= 3 ? '📝' : '⚠️');
        $priority = $rating < 3 ? self::PRIORITY_HIGH : self::PRIORITY_MEDIUM;
        $stars = str_repeat('⭐', $rating);

        $this->createNotification(
            $seller,
            self::TYPE_REVIEW_RECEIVED,
            "{$emoji} Nouvel avis : {$stars}",
            "{$reviewer->getFirstName()} vous a laissé un avis" . ($listing ? " sur \"{$listing->getTitle()}\"" : "") . " : \"{$comment}\"",
            [
                'reviewerId' => $reviewer->getId(),
                'reviewerName' => $reviewer->getFirstName(),
                'rating' => $rating,
                'comment' => substr($comment, 0, 200),
                'listingId' => $listing?->getId(),
                'listingTitle' => $listing?->getTitle()
            ],
            $priority,
            new \DateTime('+60 days')
        );
    }

    // ========== NOTIFICATIONS DIVERSES ==========

    /**
     * Notifie qu'une annonce a été publiée avec succès
     */
    public function notifyListingPublished(Listing $listing): void
    {
        $user = $listing->getUser();
        $expiresIn = $listing->getExpiresAt()->diff(new \DateTime())->days;

        $this->createNotification(
            $user,
            self::TYPE_LISTING_PUBLISHED,
            '🚀 Annonce publiée !',
            "Votre annonce \"{$listing->getTitle()}\" est maintenant en ligne. Elle sera visible pendant {$expiresIn} jours.",
            [
                'listingId' => $listing->getId(),
                'listingTitle' => $listing->getTitle(),
                'expiresAt' => $listing->getExpiresAt()->format('c'),
                'expiresIn' => $expiresIn
            ],
            self::PRIORITY_LOW,
            new \DateTime('+7 days')
        );
    }

    /**
     * Notification de bienvenue pour un nouvel utilisateur
     */
    public function notifyWelcome(User $user): void
    {
        $this->createNotification(
            $user,
            self::TYPE_WELCOME,
            '👋 Bienvenue sur Plan B !',
            "Bienvenue {$user->getFirstName()} ! Commencez par publier votre première annonce ou explorez les offres disponibles.",
            [
                'accountType' => $user->getAccountType()
            ],
            self::PRIORITY_LOW,
            new \DateTime('+7 days')
        );
    }

    // ========== MÉTHODES UTILITAIRES ==========

    /**
     * Supprime les notifications expirées et anciennes
     */
    public function cleanupOldNotifications(): array
    {
        $expiredCount = $this->notificationRepository->deleteExpired();
        $oldReadCount = $this->notificationRepository->deleteOldRead(30);

        return [
            'expiredDeleted' => $expiredCount,
            'oldReadDeleted' => $oldReadCount
        ];
    }
}
