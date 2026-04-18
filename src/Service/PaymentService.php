<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\BookingPayment;
use App\Entity\User;
use App\Repository\BookingPaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion des paiements de réservation
 * Utilise PayTech et KKiaPay comme agrégateurs de paiement
 */
class PaymentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingPaymentRepository $paymentRepository,
        private PayTechService $payTechService,
        private KKiaPayService $kkiaPayService,
        private EscrowService $escrowService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Crée un paiement pour une réservation
     */
    public function createPayment(
        Booking $booking,
        User $user,
        string $type,
        string $paymentMethod,
        ?\DateTimeInterface $dueDate = null
    ): BookingPayment {
        // Calculer le montant selon le type
        $amount = $this->calculatePaymentAmount($booking, $type);

        $payment = new BookingPayment();
        $payment->setBooking($booking);
        $payment->setUser($user);
        $payment->setType($type);
        $payment->setAmount((string)$amount);
        $payment->setPaymentMethod($paymentMethod);
        $payment->setStatus('pending');
        $payment->setDueDate($dueDate ?? new \DateTime('+7 days'));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $this->logger->info('Paiement créé', [
            'payment_id' => $payment->getId(),
            'booking_id' => $booking->getId(),
            'type' => $type,
            'amount' => $amount
        ]);

        return $payment;
    }

    /**
     * Traite un paiement via PayTech (Wave, Orange Money, etc.)
     */
    public function processPayTechPayment(BookingPayment $payment, array $data = []): array
    {
        try {
            $result = $this->payTechService->createBookingPayment(
                $payment->getId(),
                (float) $payment->getAmount(),
                "Paiement réservation #{$payment->getBooking()->getId()}",
                $payment->getUser()
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Erreur PayTech');
            }

            $payment->setExternalReference($result['ref_command'] ?? null);
            $payment->setStatus('processing');
            $payment->setMetadata(array_merge($data, ['paytech_url' => $result['payment_url'] ?? null]));

            $this->entityManager->flush();

            return [
                'success' => true,
                'payment_url' => $result['payment_url'] ?? null,
                'ref_command' => $result['ref_command'] ?? null
            ];
        } catch (\Exception $e) {
            $this->logger->error('Erreur traitement paiement PayTech', [
                'payment_id' => $payment->getId(),
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Traite un paiement via KKiaPay
     */
    public function processKKiaPayPayment(BookingPayment $payment, string $transactionId): array
    {
        try {
            // Vérifier la transaction auprès de KKiaPay
            $result = $this->kkiaPayService->verifyTransaction($transactionId);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Transaction non valide');
            }

            $payment->setTransactionId($transactionId);
            $payment->setStatus('completed');
            $payment->setPaidAt(new \DateTime());
            $payment->setPaymentMethod('kkiapay');
            $payment->setMetadata([
                'kkiapay_transaction' => $transactionId,
                'amount' => $result['amount'] ?? null,
                'phone' => $result['phone'] ?? null
            ]);

            $this->entityManager->flush();

            return [
                'success' => true,
                'transaction' => $result['transaction'] ?? null
            ];
        } catch (\Exception $e) {
            $this->logger->error('Erreur traitement paiement KKiaPay', [
                'payment_id' => $payment->getId(),
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Confirme un paiement (webhook)
     */
    public function confirmPayment(BookingPayment $payment, array $transactionData): void
    {
        $payment->setStatus('completed');
        $payment->setPaidAt(new \DateTime());
        $payment->setMetadata(array_merge($payment->getMetadata() ?? [], $transactionData));

        // Mettre à jour la réservation
        $booking = $payment->getBooking();
        if ($payment->getType() === 'deposit') {
            $booking->setDepositPaid(true);
        } elseif ($payment->getType() === 'first_rent') {
            $booking->setFirstRentPaid(true);
        }

        // Si caution + premier loyer payés, créer le compte escrow et activer la réservation
        if ($booking->isDepositPaid() && $booking->isFirstRentPaid()) {
            $this->escrowService->createEscrowAccount($booking);
            if ($booking->getStatus() === 'confirmed') {
                $booking->setStatus('active');
                $booking->setCheckInDate($booking->getStartDate());
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Paiement confirmé', [
            'payment_id' => $payment->getId(),
            'booking_id' => $booking->getId()
        ]);
    }

    /**
     * Calcule le montant d'un paiement selon son type
     */
    private function calculatePaymentAmount(Booking $booking, string $type): float
    {
        return match($type) {
            'deposit' => (float)$booking->getDepositAmount(),
            'first_rent' => (float)$booking->getMonthlyRent(),
            'monthly_rent' => (float)$booking->getMonthlyRent(),
            'charges' => (float)$booking->getCharges(),
            default => throw new \InvalidArgumentException("Type de paiement invalide: $type")
        };
    }

    /**
     * Trouve les paiements en retard
     */
    public function findOverduePayments(): array
    {
        return $this->paymentRepository->findOverduePayments();
    }

    /**
     * Crée les paiements mensuels récurrents pour une réservation active
     */
    public function createMonthlyPayments(Booking $booking): void
    {
        if ($booking->getStatus() !== 'active') {
            return;
        }

        $startDate = $booking->getStartDate();
        $endDate = $booking->getEndDate();
        $currentDate = clone $startDate;
        $currentDate->modify('+1 month'); // Premier paiement déjà fait

        while ($currentDate < $endDate) {
            // Vérifier si le paiement existe déjà
            $existingPayment = $this->paymentRepository->findOneBy([
                'booking' => $booking,
                'type' => 'monthly_rent',
                'dueDate' => $currentDate
            ]);

            if (!$existingPayment) {
                $this->createPayment(
                    $booking,
                    $booking->getTenant(),
                    'monthly_rent',
                    'paytech', // Par défaut
                    $currentDate
                );
            }

            $currentDate->modify('+1 month');
        }
    }
}
