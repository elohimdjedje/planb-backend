<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\EscrowAccount;
use App\Repository\EscrowAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion des comptes séquestres (Escrow)
 */
class EscrowService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EscrowAccountRepository $escrowRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Crée un compte séquestre pour une réservation
     */
    public function createEscrowAccount(Booking $booking): EscrowAccount
    {
        // Vérifier si un compte existe déjà
        $existing = $this->escrowRepository->findOneBy(['booking' => $booking]);
        if ($existing) {
            return $existing;
        }

        if (!$booking->isDepositPaid() || !$booking->isFirstRentPaid()) {
            throw new \InvalidArgumentException('Le paiement de la caution et du premier loyer est requis');
        }

        $escrow = new EscrowAccount();
        $escrow->setBooking($booking);
        $escrow->setDepositAmount($booking->getDepositAmount());
        $escrow->setFirstRentAmount($booking->getMonthlyRent());
        
        // Calculer la date de libération de la caution (après check-out + délai)
        $releaseDate = clone $booking->getEndDate();
        $releaseDate->modify('+30 days'); // Délai de 30 jours par défaut
        $escrow->setDepositReleaseDate($releaseDate);

        $this->entityManager->persist($escrow);
        $this->entityManager->flush();

        $this->logger->info('Compte séquestre créé', [
            'escrow_id' => $escrow->getId(),
            'booking_id' => $booking->getId()
        ]);

        return $escrow;
    }

    /**
     * Libère le premier loyer au propriétaire
     */
    public function releaseFirstRent(EscrowAccount $escrow): void
    {
        if ($escrow->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Le compte séquestre n\'est pas actif');
        }

        if ($escrow->getFirstRentReleasedAt() !== null) {
            throw new \InvalidArgumentException('Le premier loyer a déjà été libéré');
        }

        // Vérifier que le check-in a eu lieu
        $booking = $escrow->getBooking();
        if (!$booking->getCheckInDate()) {
            throw new \InvalidArgumentException('Le check-in doit avoir lieu avant la libération du premier loyer');
        }

        $escrow->setFirstRentReleasedAt(new \DateTime());

        // Si la caution est aussi libérée, marquer comme complètement libéré
        if ($escrow->getDepositReleasedAt()) {
            $escrow->setStatus('fully_released');
        }

        $this->entityManager->flush();

        $this->logger->info('Premier loyer libéré', [
            'escrow_id' => $escrow->getId(),
            'amount' => $escrow->getFirstRentAmount()
        ]);
    }

    /**
     * Libère la caution au propriétaire ou locataire
     */
    public function releaseDeposit(EscrowAccount $escrow, string $reason = 'Fin de bail'): void
    {
        if ($escrow->getStatus() !== 'active' && $escrow->getStatus() !== 'deposit_released') {
            throw new \InvalidArgumentException('Le compte séquestre n\'est pas dans un état valide');
        }

        if ($escrow->getDepositReleasedAt() !== null) {
            throw new \InvalidArgumentException('La caution a déjà été libérée');
        }

        // Vérifier que la date de libération est atteinte
        if ($escrow->getDepositReleaseDate() && $escrow->getDepositReleaseDate() > new \DateTime()) {
            throw new \InvalidArgumentException('La date de libération de la caution n\'est pas encore atteinte');
        }

        $escrow->setDepositReleasedAt(new \DateTime());
        $escrow->setReleaseReason($reason);
        $escrow->setStatus('deposit_released');

        // Si le premier loyer est aussi libéré, marquer comme complètement libéré
        if ($escrow->getFirstRentReleasedAt()) {
            $escrow->setStatus('fully_released');
        }

        $booking = $escrow->getBooking();
        $booking->setDepositReleased(true);

        $this->entityManager->flush();

        $this->logger->info('Caution libérée', [
            'escrow_id' => $escrow->getId(),
            'amount' => $escrow->getDepositAmount(),
            'reason' => $reason
        ]);
    }

    /**
     * Retient une partie de la caution (dégradations)
     */
    public function retainDeposit(EscrowAccount $escrow, float $amount, string $reason): void
    {
        if ($escrow->getDepositReleasedAt() !== null) {
            throw new \InvalidArgumentException('La caution a déjà été libérée');
        }

        $depositAmount = (float)$escrow->getDepositAmount();
        if ($amount > $depositAmount) {
            throw new \InvalidArgumentException('Le montant retenu ne peut pas dépasser la caution');
        }

        // Réduire le montant de la caution
        $remainingAmount = $depositAmount - $amount;
        $escrow->setDepositAmount((string)$remainingAmount);
        $escrow->setReleaseReason("Retenue: $reason - Montant retenu: $amount XOF");

        $this->entityManager->flush();

        $this->logger->info('Caution partiellement retenue', [
            'escrow_id' => $escrow->getId(),
            'retained_amount' => $amount,
            'remaining_amount' => $remainingAmount,
            'reason' => $reason
        ]);
    }

    /**
     * Trouve les comptes séquestres prêts à être libérés
     */
    public function findReadyForRelease(): array
    {
        return $this->escrowRepository->findReadyForRelease();
    }
}
