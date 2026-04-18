<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\BookingPaymentRepository;
use App\Repository\UserRepository;
use App\Service\PayTechService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller pour les paiements PayTech
 */
#[Route('/api')]
class PayTechController extends AbstractController
{
    public function __construct(
        private PayTechService $payTechService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Crée un paiement PayTech pour une commande
     */
    #[Route('/paytech/create-payment', name: 'paytech_create_payment', methods: ['POST'])]
    public function createPayment(Request $request, OrderRepository $orderRepository): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $orderId = $data['order_id'] ?? null;

            if (!$orderId) {
                return $this->json(['error' => 'order_id requis'], 400);
            }

            $order = $orderRepository->find($orderId);
            if (!$order) {
                return $this->json(['error' => 'Commande non trouvée'], 404);
            }

            $result = $this->payTechService->createPayment($order, $data['payment_method'] ?? 'all');

            if ($result['success']) {
                // Sauvegarder la référence PayTech
                $order->setExternalReference($result['ref_command']);
                $order->setPaymentMethod('paytech');
                $this->entityManager->flush();

                return $this->json([
                    'success' => true,
                    'payment_url' => $result['payment_url'],
                    'ref_command' => $result['ref_command']
                ]);
            }

            return $this->json(['error' => $result['error']], 400);

        } catch (\Exception $e) {
            $this->logger->error('PayTech: Erreur création paiement', ['error' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crée un paiement PayTech pour un abonnement PRO
     */
    #[Route('/paytech/subscription', name: 'paytech_subscription', methods: ['POST'])]
    public function createSubscription(Request $request): JsonResponse
    {
        try {
            // Vérifier si PayTech est activé
            if (!$this->payTechService->isEnabled()) {
                return $this->json([
                    'error' => 'PayTech n\'est pas activé. Veuillez configurer les clés API.',
                    'enabled' => false
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            /** @var User $user */
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Utilisateur non authentifié'], 401);
            }

            $data = json_decode($request->getContent(), true);
            $durationMonths = (int) ($data['duration'] ?? 1);

            $result = $this->payTechService->createSubscriptionPayment($user, $durationMonths);

            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'payment_url' => $result['payment_url'],
                    'ref_command' => $result['ref_command'],
                    'amount' => $result['amount']
                ]);
            }

            return $this->json(['error' => $result['error']], 400);

        } catch (\Exception $e) {
            $this->logger->error('PayTech: Erreur abonnement', ['error' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crée un paiement PayTech pour une réservation
     */
    #[Route('/paytech/booking-payment', name: 'paytech_booking_payment', methods: ['POST'])]
    public function createBookingPayment(
        Request $request,
        BookingPaymentRepository $paymentRepository
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Utilisateur non authentifié'], 401);
            }

            $data = json_decode($request->getContent(), true);
            $paymentId = $data['payment_id'] ?? null;

            if (!$paymentId) {
                return $this->json(['error' => 'payment_id requis'], 400);
            }

            $bookingPayment = $paymentRepository->find($paymentId);
            if (!$bookingPayment) {
                return $this->json(['error' => 'Paiement non trouvé'], 404);
            }

            $result = $this->payTechService->createBookingPayment(
                $paymentId,
                (float) $bookingPayment->getAmount(),
                'Paiement réservation #' . $paymentId,
                $user
            );

            if ($result['success']) {
                // Mettre à jour le paiement avec la référence PayTech
                $bookingPayment->setExternalReference($result['ref_command']);
                $bookingPayment->setPaymentMethod('paytech');
                $bookingPayment->setStatus('processing');
                $this->entityManager->flush();

                return $this->json([
                    'success' => true,
                    'payment_url' => $result['payment_url'],
                    'ref_command' => $result['ref_command']
                ]);
            }

            return $this->json(['error' => $result['error']], 400);

        } catch (\Exception $e) {
            $this->logger->error('PayTech: Erreur booking payment', ['error' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Webhook IPN pour recevoir les notifications de PayTech
     */
    #[Route('/webhook/paytech', name: 'paytech_webhook', methods: ['POST'])]
    public function handleWebhook(
        Request $request,
        OrderRepository $orderRepository,
        BookingPaymentRepository $bookingPaymentRepository,
        UserRepository $userRepository
    ): Response {
        try {
            $data = json_decode($request->getContent(), true) ?? $request->request->all();
            
            $this->logger->info('PayTech Webhook reçu', $data);

            // Traiter la notification
            $result = $this->payTechService->processIpnNotification($data);
            
            $refCommand = $result['ref_command'];
            $status = $result['status'];
            $customField = $result['custom_field'];

            // Déterminer le type de paiement
            $type = $customField['type'] ?? 'order';

            if ($status === 'completed') {
                switch ($type) {
                    case 'subscription':
                        $this->handleSubscriptionSuccess($customField, $userRepository);
                        break;
                    
                    case 'booking':
                        $this->handleBookingPaymentSuccess($customField, $bookingPaymentRepository);
                        break;
                    
                    default:
                        $this->handleOrderSuccess($refCommand, $orderRepository);
                        break;
                }
            } elseif ($status === 'cancelled') {
                $this->logger->info('PayTech: Paiement annulé', ['ref' => $refCommand]);
            }

            return new Response('OK', 200);

        } catch (\Exception $e) {
            $this->logger->error('PayTech Webhook error', ['error' => $e->getMessage()]);
            return new Response('Error: ' . $e->getMessage(), 500);
        }
    }

    private function handleOrderSuccess(string $refCommand, OrderRepository $orderRepository): void
    {
        // Trouver la commande par référence externe
        $order = $orderRepository->findOneBy(['externalReference' => $refCommand]);
        
        if ($order) {
            $order->setStatus('paid');
            $order->setPaidAt(new \DateTime());
            $this->entityManager->flush();
            
            $this->logger->info('PayTech: Commande payée', ['order_id' => $order->getId()]);
        }
    }

    private function handleBookingPaymentSuccess(array $customField, BookingPaymentRepository $repository): void
    {
        $paymentId = $customField['payment_id'] ?? null;
        
        if ($paymentId) {
            $payment = $repository->find($paymentId);
            if ($payment) {
                $payment->setStatus('completed');
                $payment->setPaidAt(new \DateTime());
                $this->entityManager->flush();
                
                $this->logger->info('PayTech: Paiement réservation complété', ['payment_id' => $paymentId]);
            }
        }
    }

    private function handleSubscriptionSuccess(array $customField, UserRepository $userRepository): void
    {
        $userId = $customField['user_id'] ?? null;
        $durationMonths = $customField['duration_months'] ?? 1;
        
        if ($userId) {
            $user = $userRepository->find($userId);
            if ($user) {
                // Mettre à jour l'utilisateur en PRO
                $user->setAccountType('PRO');
                
                // Calculer la date d'expiration
                $expirationDate = new \DateTime();
                $expirationDate->modify('+' . $durationMonths . ' months');
                
                // Si l'utilisateur a déjà un abonnement actif, prolonger
                $currentExpiry = $user->getSubscriptionExpiresAt();
                if ($currentExpiry && $currentExpiry > new \DateTime()) {
                    $expirationDate = (clone $currentExpiry);
                    $expirationDate->modify('+' . $durationMonths . ' months');
                }
                
                $user->setSubscriptionExpiresAt($expirationDate);
                
                $this->entityManager->flush();
                
                $this->logger->info('PayTech: Abonnement PRO activé', [
                    'user_id' => $userId,
                    'duration' => $durationMonths,
                    'expires_at' => $expirationDate->format('Y-m-d')
                ]);
            }
        }
    }
}
