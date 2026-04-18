<?php

namespace App\Controller\Api;

use App\Entity\Booking;
use App\Entity\Listing;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\ListingRepository;
use App\Service\BookingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/bookings')]
class BookingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingRepository $bookingRepository,
        private ListingRepository $listingRepository,
        private BookingService $bookingService
    ) {
    }

    /**
     * Retourne l'utilisateur authentifié ou lève une exception si non valide.
     */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié');
        }
        return $user;
    }

    /**
     * Créer une demande de réservation
     * POST /api/v1/bookings
     */
    #[Route('', name: 'api_bookings_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->requireUser();

        // Validation
        $required = ['listing_id', 'start_date', 'end_date'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return $this->json(['error' => "Le champ '$field' est requis"], Response::HTTP_BAD_REQUEST);
            }
        }

        $listing = $this->listingRepository->find($data['listing_id']);
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $startDate = new \DateTime($data['start_date']);
            $endDate = new \DateTime($data['end_date']);

            $booking = $this->bookingService->createBookingRequest(
                $listing,
                $user,
                $startDate,
                $endDate,
                $data['message'] ?? null
            );

            return $this->json([
                'success' => true,
                'message' => 'Demande de réservation créée avec succès',
                'data' => $this->serializeBooking($booking)
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la création de la réservation'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Liste des réservations de l'utilisateur
     * GET /api/v1/bookings
     */
    #[Route('', name: 'api_bookings_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $role = $request->query->get('role'); // 'owner' ou 'tenant'

        $bookings = $this->bookingRepository->findByUser($user->getId(), $role);

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'serializeBooking'], $bookings),
            'count' => count($bookings)
        ]);
    }

    /**
     * Détails d'une réservation
     * GET /api/v1/bookings/{id}
     */
    #[Route('/{id}', name: 'api_bookings_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function get(int $id): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        // Vérifier que l'utilisateur est propriétaire ou locataire
        if ($booking->getOwner()->getId() !== $user->getId() && 
            ($booking->getTenant() && $booking->getTenant()->getId() !== $user->getId())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'data' => $this->serializeBooking($booking, true)
        ]);
    }

    /**
     * Accepter une réservation
     * POST /api/v1/bookings/{id}/accept
     */
    #[Route('/{id}/accept', name: 'api_bookings_accept', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function accept(int $id, Request $request): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut accepter'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $this->bookingService->acceptBooking($booking, $data['response'] ?? null);

            return $this->json([
                'success' => true,
                'message' => 'Réservation acceptée',
                'data' => $this->serializeBooking($booking)
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Refuser une réservation
     * POST /api/v1/bookings/{id}/reject
     */
    #[Route('/{id}/reject', name: 'api_bookings_reject', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reject(int $id, Request $request): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut refuser'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $this->bookingService->rejectBooking($booking, $data['reason'] ?? null);

            return $this->json([
                'success' => true,
                'message' => 'Réservation refusée',
                'data' => $this->serializeBooking($booking)
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Marquer la visite comme effectuée
     * POST /api/v1/bookings/{id}/mark-visited
     */
    #[Route('/{id}/mark-visited', name: 'api_bookings_mark_visited', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function markVisited(int $id): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        // Propriétaire OU locataire peuvent marquer la visite
        if ($booking->getOwner()->getId() !== $user->getId() &&
            ($booking->getTenant() && $booking->getTenant()->getId() !== $user->getId())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->bookingService->markVisited($booking);
            return $this->json([
                'success' => true,
                'message' => 'Visite marquée comme effectuée',
                'data'    => $this->serializeBooking($booking),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Locataire confirme le logement après visite
     * POST /api/v1/bookings/{id}/confirm-after-visit
     */
    #[Route('/{id}/confirm-after-visit', name: 'api_bookings_confirm_after_visit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function confirmAfterVisit(int $id): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        if (!$booking->getTenant() || $booking->getTenant()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le locataire peut confirmer après visite'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->bookingService->tenantConfirmAfterVisit($booking);
            return $this->json([
                'success' => true,
                'message' => 'Logement confirmé — vous pouvez passer à la signature du contrat',
                'data'    => $this->serializeBooking($booking),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Locataire refuse le logement après visite
     * POST /api/v1/bookings/{id}/refuse-after-visit
     */
    #[Route('/{id}/refuse-after-visit', name: 'api_bookings_refuse_after_visit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function refuseAfterVisit(int $id, Request $request): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        if (!$booking->getTenant() || $booking->getTenant()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le locataire peut refuser après visite'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $this->bookingService->tenantRefuseAfterVisit($booking, $data['reason'] ?? null);
            return $this->json([
                'success' => true,
                'message' => 'Logement refusé après visite',
                'data'    => $this->serializeBooking($booking),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Annuler une réservation
     * POST /api/v1/bookings/{id}/cancel
     */
    #[Route('/{id}/cancel', name: 'api_bookings_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true);

        try {
            $this->bookingService->cancelBooking($booking, $data['reason'] ?? 'Annulation par l\'utilisateur', $user);

            return $this->json([
                'success' => true,
                'message' => 'Réservation annulée',
                'data' => $this->serializeBooking($booking)
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Vérifier la disponibilité d'une période
     * POST /api/v1/bookings/check-availability
     */
    #[Route('/check-availability', name: 'api_bookings_check_availability', methods: ['POST'])]
    public function checkAvailability(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['listing_id']) || !isset($data['start_date']) || !isset($data['end_date'])) {
            return $this->json(['error' => 'listing_id, start_date et end_date sont requis'], Response::HTTP_BAD_REQUEST);
        }

        $listing = $this->listingRepository->find($data['listing_id']);
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $startDate = new \DateTime($data['start_date']);
            $endDate = new \DateTime($data['end_date']);
            $excludeBookingId = $data['exclude_booking_id'] ?? null;

            $isAvailable = $this->bookingService->isPeriodAvailable(
                $listing->getId(),
                $startDate,
                $endDate,
                $excludeBookingId
            );

            // Calculer les montants
            $amounts = $this->bookingService->calculateTotalAmount($listing, $startDate, $endDate);

            return $this->json([
                'success' => true,
                'available' => $isAvailable,
                'amounts' => $amounts
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la vérification'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sérialise une réservation
     */
    private function serializeBooking(Booking $booking, bool $detailed = false): array
    {
        $listing  = $booking->getListing();
        $owner    = $booking->getOwner();
        $tenant   = $booking->getTenant();
        $mainImg  = $listing?->getMainImage()?->getUrl();

        $data = [
            'id'             => $booking->getId(),
            'listing_id'     => $listing?->getId(),
            'listing_title'  => $listing?->getTitle(),
            'listing_city'   => $listing?->getCity(),
            'listing_country'=> $listing?->getCountry(),
            'listing_image'  => $mainImg,
            'tenant_id'      => $tenant?->getId(),
            'tenant_name'    => $tenant ? $tenant->getFirstName() . ' ' . $tenant->getLastName() : null,
            'owner_id'       => $owner?->getId(),
            'owner_name'     => $owner ? $owner->getFirstName() . ' ' . $owner->getLastName() : null,
            'start_date'     => $booking->getStartDate()?->format('Y-m-d'),
            'end_date'       => $booking->getEndDate()?->format('Y-m-d'),
            'total_amount'   => $booking->getTotalAmount(),
            'deposit_amount' => $booking->getDepositAmount(),
            'monthly_rent'   => $booking->getMonthlyRent(),
            'charges'        => $booking->getCharges(),
            'status'         => $booking->getStatus(),
            'deposit_paid'   => $booking->isDepositPaid(),
            'first_rent_paid'=> $booking->isFirstRentPaid(),
            'deposit_released'=> $booking->isDepositReleased(),
            'duration_days'  => $booking->getDurationInDays(),
            'requested_at'   => $booking->getRequestedAt()?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $data['check_in_date']       = $booking->getCheckInDate()?->format('Y-m-d');
            $data['check_out_date']      = $booking->getCheckOutDate()?->format('Y-m-d');
            $data['accepted_at']         = $booking->getAcceptedAt()?->format('Y-m-d H:i:s');
            $data['confirmed_at']        = $booking->getConfirmedAt()?->format('Y-m-d H:i:s');
            $data['tenant_message']      = $booking->getTenantMessage();
            $data['owner_response']      = $booking->getOwnerResponse();
            $data['cancellation_reason'] = $booking->getCancellationReason();
        }

        return $data;
    }
}
