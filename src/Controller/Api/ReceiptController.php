<?php

namespace App\Controller\Api;

use App\Entity\Booking;
use App\Entity\BookingPayment;
use App\Repository\BookingRepository;
use App\Repository\ReceiptRepository;
use App\Service\ReceiptService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/receipts')]
class ReceiptController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReceiptRepository $receiptRepository,
        private BookingRepository $bookingRepository,
        private ReceiptService $receiptService
    ) {
    }

    /**
     * Liste des quittances d'une réservation
     * GET /api/v1/receipts?booking_id={id}
     */
    #[Route('', name: 'api_receipts_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $bookingId = $request->query->get('booking_id');
        if (!$bookingId) {
            return $this->json(['error' => 'booking_id est requis'], Response::HTTP_BAD_REQUEST);
        }

        $booking = $this->bookingRepository->find($bookingId);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        // Vérifier les permissions
        if ($booking->getOwner()->getId() !== $user->getId() && 
            ($booking->getTenant() && $booking->getTenant()->getId() !== $user->getId())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $receipts = $this->receiptRepository->findByBooking($bookingId);

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'serializeReceipt'], $receipts),
            'count' => count($receipts)
        ]);
    }

    /**
     * Génère une quittance pour un paiement
     * POST /api/v1/receipts/generate
     */
    #[Route('/generate', name: 'api_receipts_generate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function generate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['payment_id']) || !isset($data['period_start']) || !isset($data['period_end'])) {
            return $this->json(['error' => 'payment_id, period_start et period_end sont requis'], Response::HTTP_BAD_REQUEST);
        }

        $payment = $this->entityManager->getRepository(BookingPayment::class)->find($data['payment_id']);
        if (!$payment) {
            return $this->json(['error' => 'Paiement introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if ($payment->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        try {
            $periodStart = new \DateTime($data['period_start']);
            $periodEnd = new \DateTime($data['period_end']);

            $receipt = $this->receiptService->generateReceipt($payment, $periodStart, $periodEnd);

            return $this->json([
                'success' => true,
                'message' => 'Quittance générée avec succès',
                'data' => $this->serializeReceipt($receipt)
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Télécharger une quittance
     * GET /api/v1/receipts/{id}/download
     */
    #[Route('/{id}/download', name: 'api_receipts_download', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function download(int $id): JsonResponse
    {
        $receipt = $this->receiptRepository->find($id);
        if (!$receipt) {
            return $this->json(['error' => 'Quittance introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $booking = $receipt->getBooking();
        
        // Vérifier les permissions
        if ($booking->getOwner()->getId() !== $user->getId() && 
            ($booking->getTenant() && $booking->getTenant()->getId() !== $user->getId())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if (!$receipt->getPdfUrl()) {
            return $this->json(['error' => 'PDF non disponible'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'pdf_url' => $receipt->getPdfUrl(),
            'receipt_number' => $receipt->getReceiptNumber()
        ]);
    }

    /**
     * Trouve une quittance par son numéro
     * GET /api/v1/receipts/number/{number}
     */
    #[Route('/number/{number}', name: 'api_receipts_by_number', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getByNumber(string $number): JsonResponse
    {
        $receipt = $this->receiptRepository->findByReceiptNumber($number);
        if (!$receipt) {
            return $this->json(['error' => 'Quittance introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $booking = $receipt->getBooking();
        
        // Vérifier les permissions
        if ($booking->getOwner()->getId() !== $user->getId() && 
            ($booking->getTenant() && $booking->getTenant()->getId() !== $user->getId())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'data' => $this->serializeReceipt($receipt)
        ]);
    }

    /**
     * Sérialise une quittance
     */
    private function serializeReceipt($receipt): array
    {
        return [
            'id' => $receipt->getId(),
            'receipt_number' => $receipt->getReceiptNumber(),
            'payment_id' => $receipt->getPayment()->getId(),
            'booking_id' => $receipt->getBooking()->getId(),
            'period_start' => $receipt->getPeriodStart()->format('Y-m-d'),
            'period_end' => $receipt->getPeriodEnd()->format('Y-m-d'),
            'rent_amount' => $receipt->getRentAmount(),
            'charges_amount' => $receipt->getChargesAmount(),
            'total_amount' => $receipt->getTotalAmount(),
            'pdf_url' => $receipt->getPdfUrl(),
            'issued_at' => $receipt->getIssuedAt()->format('Y-m-d H:i:s')
        ];
    }
}
