<?php

namespace App\Service;

use App\Entity\BookingPayment;
use App\Entity\PaymentReminder;
use App\Entity\User;
use App\Repository\PaymentReminderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion des rappels de paiement
 */
class ReminderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentReminderRepository $reminderRepository,
        private NotificationService $notificationService,
        private SMSService $smsService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Crée les rappels automatiques pour un paiement
     */
    public function scheduleReminders(BookingPayment $payment): void
    {
        if (!$payment->getDueDate()) {
            return;
        }

        $dueDate = $payment->getDueDate();
        $user = $payment->getUser();

        // Rappel J-7
        $this->createReminder($payment, $user, '7_days_before', (clone $dueDate)->modify('-7 days'));

        // Rappel J-3
        $this->createReminder($payment, $user, '3_days_before', (clone $dueDate)->modify('-3 days'));

        // Rappel J-1
        $this->createReminder($payment, $user, '1_day_before', (clone $dueDate)->modify('-1 day'));

        // Rappel J+1 (retard)
        $this->createReminder($payment, $user, 'overdue_1', (clone $dueDate)->modify('+1 day'));

        // Rappel J+3
        $this->createReminder($payment, $user, 'overdue_3', (clone $dueDate)->modify('+3 days'));

        // Rappel J+7
        $this->createReminder($payment, $user, 'overdue_7', (clone $dueDate)->modify('+7 days'));
    }

    /**
     * Crée un rappel
     */
    private function createReminder(BookingPayment $payment, User $user, string $type, \DateTimeInterface $scheduledAt): void
    {
        // Vérifier si le rappel existe déjà
        $existing = $this->reminderRepository->findOneBy([
            'payment' => $payment,
            'reminderType' => $type
        ]);

        if ($existing) {
            return;
        }

        $reminder = new PaymentReminder();
        $reminder->setPayment($payment);
        $reminder->setUser($user);
        $reminder->setReminderType($type);
        $reminder->setScheduledAt($scheduledAt);
        $reminder->setStatus('pending');

        $this->entityManager->persist($reminder);
    }

    /**
     * Envoie les rappels dus
     */
    public function sendDueReminders(): int
    {
        $reminders = $this->reminderRepository->findDueReminders();
        $sent = 0;

        foreach ($reminders as $reminder) {
            try {
                $this->sendReminder($reminder);
                $reminder->markAsSent();
                $this->entityManager->flush();
                $sent++;
            } catch (\Exception $e) {
                $this->logger->error('Erreur envoi rappel', [
                    'reminder_id' => $reminder->getId(),
                    'error' => $e->getMessage()
                ]);
                $reminder->setStatus('failed');
                $this->entityManager->flush();
            }
        }

        return $sent;
    }

    /**
     * Envoie un rappel via tous les canaux
     */
    private function sendReminder(PaymentReminder $reminder): void
    {
        $payment = $reminder->getPayment();
        $user = $reminder->getUser();
        $booking = $payment->getBooking();

        $message = $this->getReminderMessage($reminder, $payment, $booking);

        // Email
        if ($user->getEmail()) {
            $this->notificationService->sendEmail(
                $user->getEmail(),
                'Rappel de paiement - Plan B',
                $message
            );
            $reminder->setEmailSent(true);
        }

        // SMS
        if ($user->getPhone()) {
            $this->smsService->send($user->getPhone(), $message);
            $reminder->setSmsSent(true);
        }

        // Push notification
        $this->notificationService->sendPushNotification(
            $user,
            'Rappel de paiement',
            $message
        );
        $reminder->setPushSent(true);
    }

    /**
     * Génère le message de rappel
     */
    private function getReminderMessage(PaymentReminder $reminder, BookingPayment $payment, $booking): string
    {
        $type = $reminder->getReminderType();
        $amount = $payment->getAmount();
        $dueDate = $payment->getDueDate();

        return match($type) {
            '7_days_before' => "Rappel: Votre loyer de {$amount} XOF est dû dans 7 jours (le {$dueDate->format('d/m/Y')}). Réservation #{$booking->getId()}",
            '3_days_before' => "Rappel: Votre loyer de {$amount} XOF est dû dans 3 jours (le {$dueDate->format('d/m/Y')}). Réservation #{$booking->getId()}",
            '1_day_before' => "Dernier rappel: Votre loyer de {$amount} XOF est dû demain (le {$dueDate->format('d/m/Y')}). Réservation #{$booking->getId()}",
            'overdue_1' => "⚠️ RETARD: Votre loyer de {$amount} XOF était dû le {$dueDate->format('d/m/Y')}. Veuillez régulariser rapidement. Réservation #{$booking->getId()}",
            'overdue_3' => "⚠️ RETARD: Votre loyer de {$amount} XOF est en retard de 3 jours. Des pénalités peuvent s'appliquer. Réservation #{$booking->getId()}",
            'overdue_7' => "🚨 RETARD CRITIQUE: Votre loyer de {$amount} XOF est en retard de 7 jours. Action immédiate requise. Réservation #{$booking->getId()}",
            default => "Rappel de paiement: {$amount} XOF - Réservation #{$booking->getId()}"
        };
    }
}
