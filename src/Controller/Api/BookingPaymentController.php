<?php

namespace App\Controller\Api;

use App\Entity\Booking;
use App\Entity\BookingPayment;
use App\Repository\BookingRepository;
use App\Repository\BookingPaymentRepository;
use App\Service\PaymentService;
use App\Service\PayTechService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/bookings')]
class BookingPaymentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingRepository $bookingRepository,
        private BookingPaymentRepository $paymentRepository,
        private PaymentService $paymentService,
        private PayTechService $payTechService
    ) {
    }

    /**
     * Créer un paiement pour une réservation
     * POST /api/v1/bookings/{id}/payments
     */
    #[Route('/{id}/payments', name: 'api_booking_payments_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createPayment(int $id, Request $request): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        // Validation
        if (!isset($data['type']) || !isset($data['payment_method'])) {
            return $this->json(['error' => 'type et payment_method sont requis'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier les permissions
        if ($booking->getTenant() && $booking->getTenant()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le locataire peut payer'], Response::HTTP_FORBIDDEN);
        }

        try {
            $dueDate = isset($data['due_date']) ? new \DateTime($data['due_date']) : null;
            
            $payment = $this->paymentService->createPayment(
                $booking,
                $user,
                $data['type'],
                $data['payment_method'],
                $dueDate
            );

            // Traiter le paiement selon la méthode
            $result = [];
            if (in_array($data['payment_method'], ['wave', 'orange_money', 'card'])) {
                $result = $this->paymentService->processPayTechPayment($payment, $data);
            } elseif (in_array($data['payment_method'], ['mtn_money', 'moov_money'])) {
                $result = $this->paymentService->processKKiaPayPayment($payment, $data['transaction_id'] ?? '');
            }

            return $this->json([
                'success' => true,
                'message' => 'Paiement créé',
                'data' => $this->serializePayment($payment),
                'payment_url' => $result['payment_url'] ?? null
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Liste des paiements d'une réservation
     * GET /api/v1/bookings/{id}/payments
     */
    #[Route('/{id}/payments', name: 'api_booking_payments_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function listPayments(int $id): JsonResponse
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

        $payments = $this->paymentRepository->findByBooking($id);

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'serializePayment'], $payments),
            'count' => count($payments)
        ]);
    }

    /**
     * Détails d'un paiement
     * GET /api/v1/payments/{id}
     */
    #[Route('/payments/{id}', name: 'api_payments_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getPayment(int $id): JsonResponse
    {
        $payment = $this->paymentRepository->find($id);
        if (!$payment) {
            return $this->json(['error' => 'Paiement introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if ($payment->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'data' => $this->serializePayment($payment, true)
        ]);
    }

    /**
     * Webhook de confirmation de paiement Wave
     * POST /api/v1/payments/wave/callback
     */
    #[Route('/payments/wave/callback', name: 'api_payments_wave_callback', methods: ['POST'])]
    public function waveCallback(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps invalide'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier la signature PayTech — protège contre les webhooks forgés
        $signature = $request->headers->get('X-Paytech-Signature') ?? ($data['hash_ipn'] ?? '');
        $payloadToVerify = array_diff_key($data, array_flip(['hash_ipn']));
        if (!$signature || !$this->payTechService->verifyWebhookSignature($payloadToVerify, $signature)) {
            return $this->json(['error' => 'Signature invalide'], Response::HTTP_UNAUTHORIZED);
        }

        // Récupérer le paiement via la référence
        $paymentId = $data['client_reference'] ?? null;
        if (!$paymentId) {
            return $this->json(['error' => 'Référence manquante'], Response::HTTP_BAD_REQUEST);
        }

        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment) {
            return $this->json(['error' => 'Paiement introuvable'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier le statut dans Wave
        if (isset($data['payment_status']) && $data['payment_status'] === 'success') {
            $this->paymentService->confirmPayment($payment, $data);
        } else {
            $payment->setStatus('failed');
            $this->entityManager->flush();
        }

        return $this->json(['success' => true]);
    }

    /**
     * Sérialise un paiement
     */
    private function serializePayment(BookingPayment $payment, bool $detailed = false): array
    {
        $data = [
            'id' => $payment->getId(),
            'booking_id' => $payment->getBooking()?->getId(),
            'type' => $payment->getType(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'status' => $payment->getStatus(),
            'payment_method' => $payment->getPaymentMethod(),
            'due_date' => $payment->getDueDate()?->format('Y-m-d'),
            'paid_at' => $payment->getPaidAt()?->format('Y-m-d H:i:s'),
            'created_at' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
            'is_overdue' => $payment->isOverdue(),
            'days_overdue' => $payment->getDaysOverdue()
        ];

        if ($detailed) {
            $data['transaction_id'] = $payment->getTransactionId();
            $data['external_reference'] = $payment->getExternalReference();
            $data['metadata'] = $payment->getMetadata();
        }

        return $data;
    }
}
