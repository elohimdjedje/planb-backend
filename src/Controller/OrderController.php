<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Operation;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\OperationRepository;
use App\Service\PayTechService;
use App\Service\KKiaPayService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur de gestion des commandes et paiements entre clients et prestataires
 * Utilise PayTech et KKiaPay comme agrégateurs de paiement
 */
#[Route('/api/v1/orders')]
class OrderController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PayTechService $payTechService,
        private KKiaPayService $kkiaPayService,
        private OrderRepository $orderRepository,
        private OperationRepository $operationRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Créer une commande et générer le lien de paiement
     * 
     * POST /api/v1/orders/create
     * Body: {
     *   "provider_id": 1,
     *   "amount": 10000,
     *   "payment_method": "wave|orange_money",
     *   "description": "Service description"
     * }
     */
    #[Route('/create', name: 'app_order_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createOrder(Request $request): JsonResponse
    {
        /** @var User $client */
        $client = $this->getUser();
        
        if (!$client) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);
        
        // Validation
        if (!isset($data['provider_id']) || !isset($data['amount']) || !isset($data['payment_method'])) {
            return $this->json([
                'error' => 'Paramètres manquants',
                'required' => ['provider_id', 'amount', 'payment_method']
            ], 400);
        }

        // Vérifier le montant minimum
        if ($data['amount'] < 100) {
            return $this->json(['error' => 'Le montant minimum est de 100 XOF'], 400);
        }

        // Vérifier le moyen de paiement
        if (!in_array($data['payment_method'], ['wave'/*, 'orange_money'*/])) {
            return $this->json(['error' => 'Moyen de paiement invalide (wave uniquement pour le moment)'], 400);
        }

        // ⚠️ ORANGE MONEY TEMPORAIREMENT DÉSACTIVÉ
        if ($data['payment_method'] === 'orange_money') {
            return $this->json([
                'error' => 'Orange Money temporairement indisponible',
                'message' => 'Veuillez utiliser Wave pour le moment'
            ], 503);
        }

        // Récupérer le prestataire
        $provider = $this->entityManager->getRepository(User::class)->find($data['provider_id']);
        if (!$provider) {
            return $this->json(['error' => 'Prestataire non trouvé'], 404);
        }

        // Créer la commande
        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setAmount($data['amount']);
        $order->setPaymentMethod($data['payment_method']);
        $order->setStatus(false); // En attente
        $order->setDescription($data['description'] ?? 'Paiement pour service');
        $order->setMetadata([
            'client_name' => $client->getFullName(),
            'provider_name' => $provider->getFullName(),
            'created_via' => 'api'
        ]);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->logger->info('Order created', [
            'order_id' => $order->getId(),
            'client_id' => $client->getId(),
            'provider_id' => $provider->getId(),
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method']
        ]);

        // Générer le lien de paiement via PayTech (supporte Wave, Orange Money, etc.)
        try {
            $paymentMethod = $data['payment_method'] ?? 'all';
            $result = $this->payTechService->createPayment($order, $paymentMethod);
            
            if (!$result['success']) {
                return $this->json([
                    'error' => $result['error'] ?? 'Erreur PayTech',
                ], 500);
            }

            // Sauvegarder la référence PayTech
            $order->setExternalReference($result['ref_command'] ?? null);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'order_id' => $order->getId(),
                'payment_method' => 'paytech',
                'payment_url' => $result['payment_url'],
                'ref_command' => $result['ref_command']
            ], 201);

        } catch (\Exception $e) {
            $this->logger->error('Error generating payment link', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Erreur lors de la génération du lien de paiement',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @deprecated Callback Wave - Utilisez PayTech à la place
     */
    #[Route('/wave/callback/{orderId}', name: 'app_order_wave_callback', methods: ['GET', 'POST'])]
    public function waveCallback(int $orderId, Request $request): JsonResponse
    {
        $this->logger->info('Wave callback deprecated - use PayTech', ['order_id' => $orderId]);
        return $this->json([
            'error' => 'Deprecated: Utilisez PayTech',
            'redirect' => '/api/webhook/paytech'
        ], 410);
    }

    /* ========================================
     * ORANGE MONEY CALLBACK - TEMPORAIREMENT DÉSACTIVÉ
     * ========================================
     * À réactiver quand l'API Orange Money sera disponible
     * 
    /**
     * Callback Orange Money après paiement
     * 
     * GET /api/v1/orders/orange-money/callback/{orderId}
     *
    #[Route('/orange-money/callback/{orderId}', name: 'app_order_om_callback', methods: ['GET', 'POST'])]
    public function orangeMoneyCallback(int $orderId, Request $request): JsonResponse
    {
        $order = $this->orderRepository->find($orderId);

        if (!$order) {
            $this->logger->error('Orange Money callback: order not found', ['order_id' => $orderId]);
            return $this->json(['error' => 'Commande non trouvée'], 404);
        }

        $queryParams = $request->query->all();
        
        $this->logger->info('Orange Money callback received', [
            'order_id' => $orderId,
            'params' => $queryParams
        ]);

        // Vérifier le statut via l'API Orange Money
        if ($order->getOmPaymentToken()) {
            try {
                $status = $this->orangeMoneyService->checkPaymentStatus($order->getOmPaymentToken());
                
                $this->logger->info('Orange Money payment status', [
                    'order_id' => $orderId,
                    'status' => $status
                ]);

                // Mettre à jour la commande
                $order->setApiStatus($status['status'] ?? 'unknown');
                $order->setOmTransactionId($status['transaction_id'] ?? null);

                if ($status['status'] === 'success' || $status['status'] === 'completed') {
                    $order->setStatus(true);
                    $order->setApiTransactionDate(new \DateTime());

                    // Créer l'opération comptable
                    $this->createOperation($order);

                    $this->logger->info('Order completed successfully', ['order_id' => $orderId]);
                }

                $this->entityManager->flush();

            } catch (\Exception $e) {
                $this->logger->error('Error checking Orange Money status', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $this->json([
            'success' => true,
            'order_id' => $orderId,
            'status' => $order->isStatus() ? 'completed' : 'pending'
        ]);
    }
    */

    /**
     * Vérifier le statut d'une commande
     * 
     * GET /api/v1/orders/{orderId}/status
     */
    #[Route('/{orderId}/status', name: 'app_order_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getOrderStatus(int $orderId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        $order = $this->orderRepository->find($orderId);

        if (!$order) {
            return $this->json(['error' => 'Commande non trouvée'], 404);
        }

        // Vérifier que l'utilisateur est le client ou le prestataire
        if ($order->getClient()->getId() !== $user->getId() && 
            $order->getProvider()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        return $this->json([
            'order' => [
                'id' => $order->getId(),
                'amount' => $order->getAmount(),
                'status' => $order->isStatus() ? 'completed' : 'pending',
                'payment_method' => $order->getPaymentMethod(),
                'description' => $order->getDescription(),
                'client' => [
                    'id' => $order->getClient()->getId(),
                    'name' => $order->getClient()->getFullName()
                ],
                'provider' => [
                    'id' => $order->getProvider()->getId(),
                    'name' => $order->getProvider()->getFullName()
                ],
                'created_at' => $order->getCreatedAt()->format('c'),
                'api_status' => $order->getApiStatus(),
                'transaction_id' => $order->getApiTransactionId()
            ]
        ]);
    }

    /**
     * Obtenir l'historique des commandes
     * 
     * GET /api/v1/orders/history
     */
    #[Route('/history', name: 'app_order_history', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getOrderHistory(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $type = $request->query->get('type', 'all'); // all, client, provider

        if ($type === 'client') {
            $orders = $this->orderRepository->findByClient($user->getId());
        } elseif ($type === 'provider') {
            $orders = $this->orderRepository->findByProvider($user->getId());
        } else {
            // Toutes les commandes (en tant que client ou prestataire)
            $ordersAsClient = $this->orderRepository->findByClient($user->getId());
            $ordersAsProvider = $this->orderRepository->findByProvider($user->getId());
            $orders = array_merge($ordersAsClient, $ordersAsProvider);
        }

        $data = array_map(function(Order $order) use ($user) {
            $isClient = $order->getClient()->getId() === $user->getId();
            
            return [
                'id' => $order->getId(),
                'amount' => $order->getAmount(),
                'status' => $order->isStatus() ? 'completed' : 'pending',
                'payment_method' => $order->getPaymentMethod(),
                'description' => $order->getDescription(),
                'role' => $isClient ? 'client' : 'provider',
                'other_party' => [
                    'id' => $isClient ? $order->getProvider()->getId() : $order->getClient()->getId(),
                    'name' => $isClient ? $order->getProvider()->getFullName() : $order->getClient()->getFullName()
                ],
                'created_at' => $order->getCreatedAt()->format('c')
            ];
        }, $orders);

        return $this->json([
            'orders' => $data,
            'total' => count($data)
        ]);
    }

    /**
     * Créer une opération comptable après paiement réussi
     */
    private function createOperation(Order $order): void
    {
        // Vérifier qu'une opération n'existe pas déjà pour cette commande
        $existingOperation = $this->operationRepository->findOneBy(['order' => $order]);
        if ($existingOperation) {
            $this->logger->info('Operation already exists', ['order_id' => $order->getId()]);
            return;
        }

        // Créer l'opération pour le client (sortie)
        $clientOperation = new Operation();
        $clientOperation->setUser($order->getClient());
        $clientOperation->setProvider($order->getProvider());
        $clientOperation->setOrder($order);
        $clientOperation->setPaymentMethod($order->getPaymentMethod());
        $clientOperation->setSens('out'); // Sortie pour le client
        $clientOperation->setAmount($order->getAmount());
        $clientOperation->setDescription("Paiement pour: " . $order->getDescription());
        
        // Calculer le solde (simplifié - à adapter selon votre logique métier)
        $currentBalance = $this->operationRepository->calculateBalance($order->getClient());
        $clientOperation->setBalanceBefore((string) $currentBalance);
        $clientOperation->setBalanceAfter((string) ($currentBalance - (float) $order->getAmount()));

        $this->entityManager->persist($clientOperation);

        // Créer l'opération pour le prestataire (entrée)
        $providerOperation = new Operation();
        $providerOperation->setUser($order->getProvider());
        $providerOperation->setProvider($order->getClient());
        $providerOperation->setOrder($order);
        $providerOperation->setPaymentMethod($order->getPaymentMethod());
        $providerOperation->setSens('in'); // Entrée pour le prestataire
        $providerOperation->setAmount($order->getAmount());
        $providerOperation->setDescription("Paiement reçu: " . $order->getDescription());
        
        $providerBalance = $this->operationRepository->calculateBalance($order->getProvider());
        $providerOperation->setBalanceBefore((string) $providerBalance);
        $providerOperation->setBalanceAfter((string) ($providerBalance + (float) $order->getAmount()));

        $this->entityManager->persist($providerOperation);
        $this->entityManager->flush();

        $this->logger->info('Operations created', [
            'order_id' => $order->getId(),
            'client_operation_id' => $clientOperation->getId(),
            'provider_operation_id' => $providerOperation->getId()
        ]);
    }
}
