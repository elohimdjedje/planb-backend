<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\BookingPayment;
use App\Entity\LatePaymentPenalty;
use App\Repository\LatePaymentPenaltyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de calcul et gestion des pénalités de retard
 */
class PenaltyService
{
    // Taux de pénalité par niveau de retard
    private const PENALTY_RATES = [
        1 => 5.0,   // 1-3 jours: 5%
        4 => 10.0,  // 4-10 jours: 10%
        11 => 15.0  // 11+ jours: 15%
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LatePaymentPenaltyRepository $penaltyRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Calcule et crée une pénalité pour un paiement en retard
     */
    public function calculatePenalty(BookingPayment $payment): ?LatePaymentPenalty
    {
        if (!$payment->isOverdue() || $payment->isCompleted()) {
            return null;
        }

        $daysLate = $payment->getDaysOverdue();
        
        // Vérifier si une pénalité existe déjà
        $existing = $this->penaltyRepository->findOneBy([
            'payment' => $payment,
            'status' => 'pending'
        ]);

        if ($existing) {
            // Recalculer si le retard a augmenté
            if ($existing->getDaysLate() < $daysLate) {
                $this->updatePenalty($existing, $daysLate, $payment);
            }
            return $existing;
        }

        // Déterminer le taux selon le nombre de jours
        $penaltyRate = $this->getPenaltyRate($daysLate);
        $penaltyAmount = ((float)$payment->getAmount()) * ($penaltyRate / 100);

        $penalty = new LatePaymentPenalty();
        $penalty->setPayment($payment);
        $penalty->setBooking($payment->getBooking());
        $penalty->setDaysLate($daysLate);
        $penalty->setPenaltyRate((string)$penaltyRate);
        $penalty->setPenaltyAmount((string)$penaltyAmount);
        $penalty->setStatus('pending');

        $this->entityManager->persist($penalty);
        $this->entityManager->flush();

        $this->logger->info('Pénalité calculée', [
            'penalty_id' => $penalty->getId(),
            'payment_id' => $payment->getId(),
            'days_late' => $daysLate,
            'amount' => $penaltyAmount
        ]);

        return $penalty;
    }

    /**
     * Met à jour une pénalité existante
     */
    private function updatePenalty(LatePaymentPenalty $penalty, int $daysLate, BookingPayment $payment): void
    {
        $penaltyRate = $this->getPenaltyRate($daysLate);
        $penaltyAmount = ((float)$payment->getAmount()) * ($penaltyRate / 100);

        $penalty->setDaysLate($daysLate);
        $penalty->setPenaltyRate((string)$penaltyRate);
        $penalty->setPenaltyAmount((string)$penaltyAmount);

        $this->entityManager->flush();
    }

    /**
     * Détermine le taux de pénalité selon le nombre de jours de retard
     */
    private function getPenaltyRate(int $daysLate): float
    {
        if ($daysLate <= 3) {
            return self::PENALTY_RATES[1];
        } elseif ($daysLate <= 10) {
            return self::PENALTY_RATES[4];
        } else {
            return self::PENALTY_RATES[11];
        }
    }

    /**
     * Calcule toutes les pénalités en retard
     */
    public function calculateAllOverduePenalties(): int
    {
        $overduePayments = $this->entityManager->getRepository(BookingPayment::class)
            ->findOverduePayments();

        $count = 0;
        foreach ($overduePayments as $payment) {
            if ($this->calculatePenalty($payment)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Marque une pénalité comme payée
     */
    public function markPenaltyAsPaid(LatePaymentPenalty $penalty): void
    {
        $currentStatus = $penalty->getStatus();
        if ($currentStatus === 'paid') {
            return; // Déjà payée
        }
        if ($currentStatus === 'waived') {
            throw new \LogicException('Impossible de marquer une pénalité annulée comme payée');
        }

        $penalty->setStatus('paid');
        $penalty->setPaidAt(new \DateTime());
        $this->entityManager->flush();

        $this->logger->info('Pénalité payée', [
            'penalty_id' => $penalty->getId(),
            'amount' => $penalty->getAmount(),
        ]);
    }

    /**
     * Annule une pénalité (exception)
     */
    public function waivePenalty(LatePaymentPenalty $penalty, string $reason): void
    {
        $currentStatus = $penalty->getStatus();
        if ($currentStatus === 'paid') {
            throw new \LogicException('Impossible d\'annuler une pénalité déjà payée');
        }
        if ($currentStatus === 'waived') {
            return; // Déjà annulée
        }

        $penalty->setStatus('waived');
        $penalty->setWaivedReason($reason);
        $this->entityManager->flush();

        $this->logger->info('Pénalité annulée', [
            'penalty_id' => $penalty->getId(),
            'reason' => $reason
        ]);
    }

    /**
     * Calcule le montant total des pénalités non payées d'une réservation
     */
    public function getTotalUnpaidPenalties(Booking $booking): float
    {
        $penalties = $this->penaltyRepository->findUnpaidByBooking($booking->getId());
        
        $total = 0;
        foreach ($penalties as $penalty) {
            $total += (float)$penalty->getPenaltyAmount();
        }

        return $total;
    }
}
