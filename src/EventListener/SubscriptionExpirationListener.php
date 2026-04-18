<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\NotificationManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Bundle\SecurityBundle\Security as SecurityBundle;

/**
 * Vérifie automatiquement l'expiration des abonnements PRO à chaque requête
 */
class SubscriptionExpirationListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecurityBundle $security,
        private NotificationManagerService $notificationManager
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        // Ne traiter que la requête principale
        if (!$event->isMainRequest()) {
            return;
        }

        // Récupérer l'utilisateur connecté
        $user = $this->security->getUser();

        // Si pas d'utilisateur ou pas une instance de User, ignorer
        if (!$user instanceof User) {
            return;
        }

        // Ignorer les PRO à vie
        if ($user->isLifetimePro()) {
            return;
        }

        // Vérifier si le compte PRO a expiré
        if ($user->getAccountType() === 'PRO') {
            $expiresAt = $user->getSubscriptionExpiresAt();

            // Si date d'expiration dépassée
            if ($expiresAt && $expiresAt < new \DateTimeImmutable()) {
                // Repasser en FREE
                $user->setAccountType('FREE');
                $user->setSubscriptionExpiresAt(null);

                // Mettre à jour l'abonnement si existe
                $subscription = $user->getSubscription();
                if ($subscription) {
                    $subscription->setStatus('expired');
                }

                $this->entityManager->flush();

                // Même mail / SMS / notif que la commande CRON (le mailer est le même qu’auth)
                $this->notificationManager->notifySubscriptionExpired($user);
            }
        }
    }
}
