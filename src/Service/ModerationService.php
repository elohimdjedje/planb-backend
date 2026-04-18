<?php

namespace App\Service;

use App\Entity\Listing;
use App\Entity\ModerationAction;
use App\Entity\Report;
use App\Entity\User;
use App\Repository\ModerationActionRepository;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service centralisé pour la modération
 */
class ModerationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ModerationActionRepository $moderationActionRepository,
        private ReportRepository $reportRepository,
        private NotificationManagerService $notificationManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Masquer une annonce
     */
    public function hideListing(Listing $listing, User $moderator, string $reason, ?Report $report = null): ModerationAction
    {
        $listing->setStatus('hidden');
        $listing->setUpdatedAt(new \DateTimeImmutable());

        $action = $this->createAction(
            $moderator,
            ModerationAction::ACTION_HIDE,
            ModerationAction::TARGET_LISTING,
            $listing->getId(),
            $reason,
            null,
            $report
        );

        // Notifier l'utilisateur
        $this->notificationManager->createNotification(
            $listing->getUser(),
            'listing_hidden',
            'Annonce masquée',
            "Votre annonce \"{$listing->getTitle()}\" a été masquée. Raison: {$reason}",
            ['listingId' => $listing->getId()],
            'high'
        );

        $this->entityManager->flush();

        $this->logger->info('Listing hidden', [
            'listing_id' => $listing->getId(),
            'moderator_id' => $moderator->getId(),
            'reason' => $reason
        ]);

        return $action;
    }

    /**
     * Supprimer une annonce
     */
    public function deleteListing(Listing $listing, User $moderator, string $reason, ?Report $report = null): ModerationAction
    {
        $action = $this->createAction(
            $moderator,
            ModerationAction::ACTION_DELETE,
            ModerationAction::TARGET_LISTING,
            $listing->getId(),
            $reason,
            null,
            $report
        );

        // Notifier l'utilisateur avant suppression
        $this->notificationManager->createNotification(
            $listing->getUser(),
            'listing_deleted',
            'Annonce supprimée',
            "Votre annonce \"{$listing->getTitle()}\" a été supprimée pour violation des règles. Raison: {$reason}",
            ['listingId' => $listing->getId()],
            'urgent'
        );

        // Supprimer l'annonce
        $this->entityManager->remove($listing);
        $this->entityManager->flush();

        $this->logger->warning('Listing deleted', [
            'listing_id' => $listing->getId(),
            'moderator_id' => $moderator->getId(),
            'reason' => $reason
        ]);

        return $action;
    }

    /**
     * Avertir un utilisateur
     */
    public function warnUser(User $user, User $moderator, string $reason, ?Report $report = null): ModerationAction
    {
        // Incrémenter le compteur d'avertissements
        $warnings = $user->getWarningsCount() ?? 0;
        $user->setWarningsCount($warnings + 1);

        $action = $this->createAction(
            $moderator,
            ModerationAction::ACTION_WARN,
            ModerationAction::TARGET_USER,
            $user->getId(),
            $reason,
            ['warningsCount' => $warnings + 1],
            $report
        );

        // Notifier l'utilisateur
        $this->notificationManager->createNotification(
            $user,
            'user_warned',
            'Avertissement',
            "Vous avez reçu un avertissement. Raison: {$reason}. Avertissements: " . ($warnings + 1) . "/3",
            ['warningsCount' => $warnings + 1],
            'high'
        );

        // Bannir automatiquement après 3 avertissements
        if ($warnings + 1 >= 3) {
            $this->banUser($user, $moderator, '3 avertissements atteints', null, 30); // 30 jours
        }

        $this->entityManager->flush();

        return $action;
    }

    /**
     * Suspendre un utilisateur temporairement
     */
    public function suspendUser(User $user, User $moderator, string $reason, int $days, ?Report $report = null): ModerationAction
    {
        $expiresAt = new \DateTime();
        $expiresAt->modify("+{$days} days");

        $user->setIsSuspended(true);
        $user->setSuspendedUntil($expiresAt);

        $action = $this->createAction(
            $moderator,
            ModerationAction::ACTION_SUSPEND,
            ModerationAction::TARGET_USER,
            $user->getId(),
            $reason,
            ['days' => $days],
            $report
        );
        $action->setExpiresAt($expiresAt);

        // Notifier l'utilisateur
        $this->notificationManager->createNotification(
            $user,
            'user_suspended',
            'Compte suspendu',
            "Votre compte a été suspendu jusqu'au " . $expiresAt->format('d/m/Y') . ". Raison: {$reason}",
            ['suspendedUntil' => $expiresAt->format('c')],
            'urgent'
        );

        $this->entityManager->flush();

        $this->logger->warning('User suspended', [
            'user_id' => $user->getId(),
            'moderator_id' => $moderator->getId(),
            'days' => $days,
            'reason' => $reason
        ]);

        return $action;
    }

    /**
     * Bannir un utilisateur
     */
    public function banUser(User $user, User $moderator, string $reason, ?Report $report = null, ?int $days = null): ModerationAction
    {
        if ($days !== null) {
            // Bannissement temporaire
            $expiresAt = new \DateTime();
            $expiresAt->modify("+{$days} days");
            $user->setBannedUntil($expiresAt);
        } else {
            // Bannissement permanent
            $user->setIsBanned(true);
            $expiresAt = null;
        }

        $action = $this->createAction(
            $moderator,
            ModerationAction::ACTION_BAN,
            ModerationAction::TARGET_USER,
            $user->getId(),
            $reason,
            ['permanent' => $days === null, 'days' => $days],
            $report
        );

        if ($expiresAt) {
            $action->setExpiresAt($expiresAt);
        }

        // Notifier l'utilisateur
        $message = $days !== null
            ? "Votre compte a été banni jusqu'au " . $expiresAt->format('d/m/Y') . ". Raison: {$reason}"
            : "Votre compte a été banni définitivement. Raison: {$reason}";

        $this->notificationManager->createNotification(
            $user,
            'user_banned',
            'Compte banni',
            $message,
            ['permanent' => $days === null, 'bannedUntil' => $expiresAt?->format('c')],
            'urgent'
        );

        $this->entityManager->flush();

        $this->logger->error('User banned', [
            'user_id' => $user->getId(),
            'moderator_id' => $moderator->getId(),
            'permanent' => $days === null,
            'reason' => $reason
        ]);

        return $action;
    }

    /**
     * Débannir un utilisateur
     */
    public function unbanUser(User $user, User $moderator, string $reason): ModerationAction
    {
        $user->setIsBanned(false);
        $user->setBannedUntil(null);
        $user->setIsSuspended(false);
        $user->setSuspendedUntil(null);

        $action = $this->createAction(
            $moderator,
            ModerationAction::ACTION_UNBAN,
            ModerationAction::TARGET_USER,
            $user->getId(),
            $reason
        );

        // Notifier l'utilisateur
        $this->notificationManager->createNotification(
            $user,
            'user_unbanned',
            'Compte réactivé',
            "Votre compte a été réactivé. Raison: {$reason}",
            null,
            'medium'
        );

        $this->entityManager->flush();

        $this->logger->info('User unbanned', [
            'user_id' => $user->getId(),
            'moderator_id' => $moderator->getId()
        ]);

        return $action;
    }

    /**
     * Approuver un signalement (rejeter)
     */
    public function approveReport(Report $report, User $moderator, string $notes): ModerationAction
    {
        $report->setStatus('dismissed');
        $report->setReviewedAt(new \DateTimeImmutable());
        $report->setAdminNotes($notes);

        $action = $this->createAction(
            $moderator,
            ModerationAction::ACTION_APPROVE,
            ModerationAction::TARGET_LISTING,
            $report->getListing()->getId(),
            'Signalement rejeté',
            ['reportId' => $report->getId()],
            $report
        );

        $this->entityManager->flush();

        return $action;
    }

    /**
     * Traiter un signalement (masquer ou supprimer)
     */
    public function processReport(Report $report, User $moderator, string $action, string $reason, ?string $notes = null): ModerationAction
    {
        $listing = $report->getListing();
        $report->setStatus('actioned');
        $report->setReviewedAt(new \DateTimeImmutable());
        $report->setAdminNotes($notes);

        switch ($action) {
            case 'hide':
                return $this->hideListing($listing, $moderator, $reason, $report);
            case 'delete':
                return $this->deleteListing($listing, $moderator, $reason, $report);
            case 'warn':
                return $this->warnUser($listing->getUser(), $moderator, $reason, $report);
            case 'ban':
                return $this->banUser($listing->getUser(), $moderator, $reason, $report);
            default:
                throw new \InvalidArgumentException("Action invalide: {$action}");
        }
    }

    /**
     * Créer une action de modération
     */
    private function createAction(
        User $moderator,
        string $actionType,
        string $targetType,
        int $targetId,
        string $reason,
        ?array $metadata = null,
        ?Report $report = null
    ): ModerationAction {
        $action = new ModerationAction();
        $action->setModerator($moderator);
        $action->setActionType($actionType);
        $action->setTargetType($targetType);
        $action->setTargetId($targetId);
        $action->setReason($reason);
        $action->setMetadata($metadata);
        $action->setRelatedReport($report);

        $this->entityManager->persist($action);

        return $action;
    }

    /**
     * Obtenir l'historique de modération d'un utilisateur
     */
    public function getUserModerationHistory(User $user): array
    {
        return $this->moderationActionRepository->findBy([
            'targetType' => ModerationAction::TARGET_USER,
            'targetId' => $user->getId()
        ], ['createdAt' => 'DESC']);
    }

    /**
     * Obtenir l'historique de modération d'une annonce
     */
    public function getListingModerationHistory(Listing $listing): array
    {
        return $this->moderationActionRepository->findBy([
            'targetType' => ModerationAction::TARGET_LISTING,
            'targetId' => $listing->getId()
        ], ['createdAt' => 'DESC']);
    }

    /**
     * Vérifier si un utilisateur est banni ou suspendu
     */
    public function isUserRestricted(User $user): bool
    {
        if ($user->isIsBanned()) {
            return true;
        }

        if ($user->isIsSuspended() && $user->getSuspendedUntil() && $user->getSuspendedUntil() > new \DateTime()) {
            return true;
        }

        if ($user->getBannedUntil() && $user->getBannedUntil() > new \DateTime()) {
            return true;
        }

        return false;
    }
}


