<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Listing;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\AvailabilityCalendarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion des réservations
 */
class BookingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingRepository $bookingRepository,
        private AvailabilityCalendarRepository $availabilityRepository,
        private LoggerInterface $logger,
        private SocketIoService $socketIoService
    ) {
    }

    /**
     * Crée une demande de réservation
     */
    public function createBookingRequest(
        Listing $listing,
        User $tenant,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        ?string $message = null
    ): Booking {
        // Vérifier que l'utilisateur n'est pas le propriétaire
        if ($listing->getUser()->getId() === $tenant->getId()) {
            throw new \InvalidArgumentException('Vous ne pouvez pas réserver votre propre annonce');
        }

        // Vérifier la disponibilité
        if (!$this->isPeriodAvailable($listing->getId(), $startDate, $endDate)) {
            throw new \InvalidArgumentException('La période sélectionnée n\'est pas disponible');
        }

        // Calculer les montants
        $monthlyRent = (float)$listing->getPrice();
        $durationDays = $startDate->diff($endDate)->days;
        $months = $durationDays / 30;
        $totalAmount = $monthlyRent * max(1, ceil($months));
        
        // Caution par défaut: 1 mois de loyer
        $depositMonths = 1.0;
        $depositAmount = $monthlyRent * $depositMonths;

        // Créer la réservation
        $booking = new Booking();
        $booking->setListing($listing);
        $booking->setTenant($tenant);
        $booking->setOwner($listing->getUser());
        $booking->setStartDate($startDate);
        $booking->setEndDate($endDate);
        $booking->setTotalAmount((string)$totalAmount);
        $booking->setDepositAmount((string)$depositAmount);
        $booking->setMonthlyRent((string)$monthlyRent);
        $booking->setStatus('pending');
        $booking->setTenantMessage($message);
        $booking->setRequestedAt(new \DateTime());

        $this->entityManager->persist($booking);
        $this->entityManager->flush();

        $this->logger->info('Demande de réservation créée', [
            'booking_id' => $booking->getId(),
            'listing_id' => $listing->getId(),
            'tenant_id' => $tenant->getId()
        ]);

        return $booking;
    }

    /**
     * Accepte une réservation
     */
    public function acceptBooking(Booking $booking, ?string $response = null): void
    {
        if ($booking->getStatus() !== 'pending') {
            throw new \InvalidArgumentException('Seules les réservations en attente peuvent être acceptées');
        }

        $booking->setStatus('accepted');
        $booking->setAcceptedAt(new \DateTime());
        $booking->setOwnerResponse($response);

        $this->entityManager->flush();
        $this->socketIoService->emitBookingUpdate($booking->getId(), 'accepted', [], $booking->getOwner()->getId(), $booking->getTenant()?->getId());

        $this->logger->info('Réservation acceptée', [
            'booking_id' => $booking->getId()
        ]);
    }

    /**
     * Refuse une réservation
     */
    public function rejectBooking(Booking $booking, ?string $reason = null): void
    {
        if ($booking->getStatus() !== 'pending') {
            throw new \InvalidArgumentException('Seules les réservations en attente peuvent être refusées');
        }

        $booking->setStatus('rejected');
        $booking->setOwnerResponse($reason);

        $this->entityManager->flush();
        $this->socketIoService->emitBookingUpdate($booking->getId(), 'rejected', [], $booking->getOwner()->getId(), $booking->getTenant()?->getId());

        $this->logger->info('Réservation refusée', [
            'booking_id' => $booking->getId()
        ]);
    }

    /**
     * Marque la visite comme effectuée (propriétaire ou système)
     * accepted → visited
     */
    public function markVisited(Booking $booking): void
    {
        if ($booking->getStatus() !== 'accepted') {
            throw new \InvalidArgumentException('La réservation doit être acceptée avant de marquer la visite');
        }

        $booking->setStatus('visited');
        $this->entityManager->flush();
        $this->socketIoService->emitBookingUpdate($booking->getId(), 'visited', [], $booking->getOwner()->getId(), $booking->getTenant()?->getId());

        $this->logger->info('Visite effectuée', ['booking_id' => $booking->getId()]);
    }

    /**
     * Locataire confirme le logement après visite
     * visited → confirmed
     */
    public function tenantConfirmAfterVisit(Booking $booking): void
    {
        if ($booking->getStatus() !== 'visited') {
            throw new \InvalidArgumentException('Le statut doit être "visited" pour confirmer après visite');
        }

        $booking->setStatus('confirmed');
        $booking->setConfirmedAt(new \DateTime());

        $this->entityManager->flush();
        $this->socketIoService->emitBookingUpdate($booking->getId(), 'confirmed', [], $booking->getOwner()->getId(), $booking->getTenant()?->getId());

        $this->logger->info('Locataire a confirmé le logement après visite', [
            'booking_id' => $booking->getId(),
        ]);
    }

    /**
     * Locataire refuse le logement après visite
     * visited → rejected
     */
    public function tenantRefuseAfterVisit(Booking $booking, ?string $reason = null): void
    {
        if ($booking->getStatus() !== 'visited') {
            throw new \InvalidArgumentException('Le statut doit être "visited" pour refuser après visite');
        }

        $booking->setStatus('rejected');
        $booking->setCancellationReason($reason);
        $this->entityManager->flush();
        $this->socketIoService->emitBookingUpdate($booking->getId(), 'rejected', [], $booking->getOwner()->getId(), $booking->getTenant()?->getId());

        $this->logger->info('Locataire a refusé le logement après visite', [
            'booking_id' => $booking->getId(),
        ]);
    }

    /**
     * Confirme une réservation (après paiement — flux legacy)
     */
    public function confirmBooking(Booking $booking): void
    {
        if (!in_array($booking->getStatus(), ['accepted', 'visited'], true)) {
            throw new \InvalidArgumentException('Seules les réservations acceptées ou après-visite peuvent être confirmées');
        }

        if (!$booking->isDepositPaid() || !$booking->isFirstRentPaid()) {
            throw new \InvalidArgumentException('Le paiement de la caution et du premier loyer est requis');
        }

        $booking->setStatus('confirmed');
        $booking->setConfirmedAt(new \DateTime());

        // Bloquer les dates dans le calendrier
        $this->blockDates($booking->getListing()->getId(), $booking->getStartDate(), $booking->getEndDate());

        $this->entityManager->flush();

        $this->logger->info('Réservation confirmée', [
            'booking_id' => $booking->getId()
        ]);
    }

    /**
     * Annule une réservation
     */
    public function cancelBooking(Booking $booking, string $reason, User $cancelledBy): void
    {
        if (!$booking->canBeCancelled()) {
            throw new \InvalidArgumentException('Cette réservation ne peut pas être annulée');
        }

        // Capturer le statut AVANT la mutation pour la vérification unblockDates
        $originalStatus = $booking->getStatus();

        $booking->setStatus('cancelled');
        $booking->setCancellationReason($reason);

        // Libérer les dates si la réservation était confirmée ou active
        if ($originalStatus === 'confirmed' || $originalStatus === 'active') {
            $this->unblockDates($booking->getListing()->getId(), $booking->getStartDate(), $booking->getEndDate());
        }

        $this->entityManager->flush();

        $this->logger->info('Réservation annulée', [
            'booking_id' => $booking->getId(),
            'cancelled_by' => $cancelledBy->getId()
        ]);
    }

    /**
     * Active une réservation (check-in)
     */
    public function activateBooking(Booking $booking, \DateTimeInterface $checkInDate): void
    {
        if ($booking->getStatus() !== 'confirmed') {
            throw new \InvalidArgumentException('Seules les réservations confirmées peuvent être activées');
        }

        $booking->setStatus('active');
        $booking->setCheckInDate($checkInDate);

        $this->entityManager->flush();

        $this->logger->info('Réservation activée', [
            'booking_id' => $booking->getId()
        ]);
    }

    /**
     * Termine une réservation (check-out)
     */
    public function completeBooking(Booking $booking, \DateTimeInterface $checkOutDate): void
    {
        if ($booking->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Seules les réservations actives peuvent être terminées');
        }

        $booking->setStatus('completed');
        $booking->setCheckOutDate($checkOutDate);

        // Libérer les dates
        $this->unblockDates($booking->getListing()->getId(), $booking->getStartDate(), $booking->getEndDate());

        $this->entityManager->flush();

        $this->logger->info('Réservation terminée', [
            'booking_id' => $booking->getId()
        ]);
    }

    /**
     * Vérifie si une période est disponible
     */
    public function isPeriodAvailable(int $listingId, \DateTimeInterface $startDate, \DateTimeInterface $endDate, ?int $excludeBookingId = null): bool
    {
        // Vérifier dans les réservations existantes
        if (!$this->bookingRepository->isPeriodAvailable($listingId, $startDate, $endDate, $excludeBookingId)) {
            return false;
        }

        // Vérifier dans le calendrier de disponibilité
        return $this->availabilityRepository->isPeriodAvailable($listingId, $startDate, $endDate);
    }

    /**
     * Bloque des dates dans le calendrier
     */
    private function blockDates(int $listingId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): void
    {
        $listing = $this->entityManager->getRepository(Listing::class)->find($listingId);
        if (!$listing) {
            return;
        }

        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $calendar = $this->availabilityRepository->findOneBy([
                'listing' => $listing,
                'date' => $currentDate
            ]);

            if (!$calendar) {
                $calendar = new \App\Entity\AvailabilityCalendar();
                $calendar->setListing($listing);
                $calendar->setDate(clone $currentDate);
            }

            $calendar->setIsAvailable(false);
            $calendar->setIsBlocked(true);
            $calendar->setBlockReason('Réservé');

            $this->entityManager->persist($calendar);
            $currentDate->modify('+1 day');
        }

        $this->entityManager->flush();
    }

    /**
     * Libère des dates dans le calendrier
     */
    private function unblockDates(int $listingId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): void
    {
        $listing = $this->entityManager->getRepository(Listing::class)->find($listingId);
        if (!$listing) {
            return;
        }

        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $calendar = $this->availabilityRepository->findOneBy([
                'listing' => $listing,
                'date' => $currentDate
            ]);

            if ($calendar && $calendar->isBlocked()) {
                $calendar->setIsAvailable(true);
                $calendar->setIsBlocked(false);
                $calendar->setBlockReason(null);
                $this->entityManager->persist($calendar);
            }

            $currentDate->modify('+1 day');
        }

        $this->entityManager->flush();
    }

    /**
     * Calcule le montant total d'une réservation
     */
    public function calculateTotalAmount(Listing $listing, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $monthlyRent = (float)$listing->getPrice();
        $durationDays = $startDate->diff($endDate)->days;
        $months = $durationDays / 30;
        $totalAmount = $monthlyRent * max(1, ceil($months));
        
        // Caution par défaut: 1 mois de loyer
        $depositMonths = 1.0;
        $depositAmount = $monthlyRent * $depositMonths;

        return [
            'monthly_rent' => $monthlyRent,
            'duration_days' => $durationDays,
            'duration_months' => ceil($months),
            'total_amount' => $totalAmount,
            'deposit_amount' => $depositAmount,
            'first_rent' => $monthlyRent
        ];
    }
}
