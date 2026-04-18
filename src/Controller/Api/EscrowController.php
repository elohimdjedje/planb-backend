<?php

namespace App\Controller\Api;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Repository\EscrowAccountRepository;
use App\Service\EscrowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/escrow')]
class EscrowController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingRepository $bookingRepository,
        private EscrowAccountRepository $escrowRepository,
        private EscrowService $escrowService
    ) {
    }

    /**
     * Récupère le compte séquestre d'une réservation
     * GET /api/v1/escrow/booking/{id}
     */
    #[Route('/booking/{id}', name: 'api_escrow_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function get(int $id): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        // Vérifier les permissions
        if ($booking->getOwner()->getId() !== $user->getId() && 
            ($booking->getTenant() && $booking->getTenant()->getId() !== $user->getId())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $escrow = $this->escrowRepository->findOneBy(['booking' => $booking]);
        if (!$escrow) {
            return $this->json(['error' => 'Compte séquestre introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $this->serializeEscrow($escrow)
        ]);
    }

    /**
     * Libère le premier loyer
     * POST /api/v1/escrow/{id}/release-first-rent
     */
    #[Route('/{id}/release-first-rent', name: 'api_escrow_release_first_rent', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function releaseFirstRent(int $id): JsonResponse
    {
        $escrow = $this->escrowRepository->find($id);
        if (!$escrow) {
            return $this->json(['error' => 'Compte séquestre introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $booking = $escrow->getBooking();
        
        // Seul le propriétaire peut libérer
        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut libérer le premier loyer'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->escrowService->releaseFirstRent($escrow);

            return $this->json([
                'success' => true,
                'message' => 'Premier loyer libéré avec succès',
                'data' => $this->serializeEscrow($escrow)
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Libère la caution
     * POST /api/v1/escrow/{id}/release-deposit
     */
    #[Route('/{id}/release-deposit', name: 'api_escrow_release_deposit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function releaseDeposit(int $id, Request $request): JsonResponse
    {
        $escrow = $this->escrowRepository->find($id);
        if (!$escrow) {
            return $this->json(['error' => 'Compte séquestre introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $booking = $escrow->getBooking();
        
        // Seul le propriétaire peut libérer
        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut libérer la caution'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $reason = $data['reason'] ?? 'Fin de bail';

            $this->escrowService->releaseDeposit($escrow, $reason);

            return $this->json([
                'success' => true,
                'message' => 'Caution libérée avec succès',
                'data' => $this->serializeEscrow($escrow)
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Retient une partie de la caution
     * POST /api/v1/escrow/{id}/retain-deposit
     */
    #[Route('/{id}/retain-deposit', name: 'api_escrow_retain_deposit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function retainDeposit(int $id, Request $request): JsonResponse
    {
        $escrow = $this->escrowRepository->find($id);
        if (!$escrow) {
            return $this->json(['error' => 'Compte séquestre introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $booking = $escrow->getBooking();
        
        // Seul le propriétaire peut retenir
        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut retenir la caution'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['amount']) || !isset($data['reason'])) {
            return $this->json(['error' => 'amount et reason sont requis'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->escrowService->retainDeposit($escrow, (float)$data['amount'], $data['reason']);

            return $this->json([
                'success' => true,
                'message' => 'Caution partiellement retenue',
                'data' => $this->serializeEscrow($escrow)
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Sérialise un compte séquestre
     */
    private function serializeEscrow($escrow): array
    {
        return [
            'id' => $escrow->getId(),
            'booking_id' => $escrow->getBooking()->getId(),
            'deposit_amount' => $escrow->getDepositAmount(),
            'first_rent_amount' => $escrow->getFirstRentAmount(),
            'total_held' => $escrow->getTotalHeld(),
            'status' => $escrow->getStatus(),
            'deposit_held_at' => $escrow->getDepositHeldAt()->format('Y-m-d H:i:s'),
            'deposit_release_date' => $escrow->getDepositReleaseDate()?->format('Y-m-d'),
            'deposit_released_at' => $escrow->getDepositReleasedAt()?->format('Y-m-d H:i:s'),
            'first_rent_released_at' => $escrow->getFirstRentReleasedAt()?->format('Y-m-d H:i:s'),
            'release_reason' => $escrow->getReleaseReason(),
            'can_release_deposit' => $escrow->canReleaseDeposit(),
            'can_release_first_rent' => $escrow->canReleaseFirstRent()
        ];
    }
}
