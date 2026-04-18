<?php

namespace App\Controller\Api;

use App\Entity\Listing;
use App\Repository\ListingRepository;
use App\Repository\AvailabilityCalendarRepository;
use App\Service\BookingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/availability')]
class AvailabilityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ListingRepository $listingRepository,
        private AvailabilityCalendarRepository $availabilityRepository,
        private BookingService $bookingService
    ) {
    }

    /**
     * Récupère le calendrier de disponibilité d'une annonce
     * GET /api/v1/availability/listing/{id}?start_date=2024-01-01&end_date=2024-12-31
     */
    #[Route('/listing/{id}', name: 'api_availability_get', methods: ['GET'])]
    public function getAvailability(int $id, Request $request): JsonResponse
    {
        $listing = $this->listingRepository->find($id);
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $startDate = $request->query->get('start_date', date('Y-m-01'));
        $endDate = $request->query->get('end_date', date('Y-m-t'));

        // Valider le format des dates (protection contre injection et DoS)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return $this->json(['error' => 'Format de date invalide (YYYY-MM-DD attendu)'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $start = \DateTime::createFromFormat('Y-m-d', $startDate);
            $end   = \DateTime::createFromFormat('Y-m-d', $endDate);

            if (!$start || !$end || $start > $end) {
                return $this->json(['error' => 'Plage de dates invalide'], Response::HTTP_BAD_REQUEST);
            }

            // Limiter à 366 jours max pour éviter les boucles DoS
            $diffDays = $start->diff($end)->days;
            if ($diffDays > 366) {
                return $this->json(['error' => 'La plage de dates ne peut pas dépasser 366 jours'], Response::HTTP_BAD_REQUEST);
            }

            $availableDates = $this->availabilityRepository->findAvailableDates($id, $start, $end);

            // Vérifier aussi les réservations existantes
            $bookings = $this->entityManager->getRepository(\App\Entity\Booking::class)
                ->findActiveByListing($id);

            $blockedDates = [];
            foreach ($bookings as $booking) {
                $current = clone $booking->getStartDate();
                while ($current <= $booking->getEndDate()) {
                    $blockedDates[] = $current->format('Y-m-d');
                    $current->modify('+1 day');
                }
            }

            return $this->json([
                'success' => true,
                'data' => [
                    'available_dates' => array_map(fn($d) => $d->getDate()->format('Y-m-d'), $availableDates),
                    'blocked_dates' => $blockedDates,
                    'listing_id' => $id
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la récupération'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Bloque des dates dans le calendrier
     * POST /api/v1/availability/listing/{id}/block
     */
    #[Route('/listing/{id}/block', name: 'api_availability_block', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function blockDates(int $id, Request $request): JsonResponse
    {
        $listing = $this->listingRepository->find($id);
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut bloquer des dates'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['dates']) || !is_array($data['dates'])) {
            return $this->json(['error' => 'dates (array) est requis'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $blocked = 0;
            foreach ($data['dates'] as $dateStr) {
                $date = new \DateTime($dateStr);
                
                $calendar = $this->availabilityRepository->findOneBy([
                    'listing' => $listing,
                    'date' => $date
                ]);

                if (!$calendar) {
                    $calendar = new \App\Entity\AvailabilityCalendar();
                    $calendar->setListing($listing);
                    $calendar->setDate($date);
                }

                $calendar->setIsBlocked(true);
                $calendar->setIsAvailable(false);
                $calendar->setBlockReason($data['reason'] ?? 'Bloqué par le propriétaire');

                $this->entityManager->persist($calendar);
                $blocked++;
            }

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => "$blocked date(s) bloquée(s)",
                'blocked_count' => $blocked
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Débloque des dates
     * POST /api/v1/availability/listing/{id}/unblock
     */
    #[Route('/listing/{id}/unblock', name: 'api_availability_unblock', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unblockDates(int $id, Request $request): JsonResponse
    {
        $listing = $this->listingRepository->find($id);
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut débloquer des dates'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['dates']) || !is_array($data['dates'])) {
            return $this->json(['error' => 'dates (array) est requis'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $unblocked = 0;
            foreach ($data['dates'] as $dateStr) {
                $date = new \DateTime($dateStr);
                
                $calendar = $this->availabilityRepository->findOneBy([
                    'listing' => $listing,
                    'date' => $date
                ]);

                if ($calendar) {
                    $calendar->setIsBlocked(false);
                    $calendar->setIsAvailable(true);
                    $calendar->setBlockReason(null);

                    $this->entityManager->persist($calendar);
                    $unblocked++;
                }
            }

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => "$unblocked date(s) débloquée(s)",
                'unblocked_count' => $unblocked
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
