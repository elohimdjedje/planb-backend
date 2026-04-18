<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'intégration PayTech
 * Documentation: https://paytech.sn/documentation
 * 
 * PayTech supporte: Wave, Orange Money, Free Money, Carte bancaire
 */
class PayTechService
{
    private const API_URL = 'https://paytech.sn/api/payment/request-payment';
    private const MOBILE_URL = 'https://paytech.sn/api/payment/request-payment';
    
    private string $apiKey;
    private string $secretKey;
    private string $env;
    private string $ipnUrl;
    private string $successUrl;
    private string $cancelUrl;
    private bool $enabled;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        string $paytechApiKey,
        string $paytechSecretKey,
        string $paytechEnv,
        string $paytechIpnUrl,
        string $paytechSuccessUrl,
        string $paytechCancelUrl,
        bool $enabled = true
    ) {
        $this->apiKey = $paytechApiKey;
        $this->secretKey = $paytechSecretKey;
        $this->env = $paytechEnv;
        $this->ipnUrl = $paytechIpnUrl;
        $this->successUrl = $paytechSuccessUrl;
        $this->cancelUrl = $paytechCancelUrl;
        $this->enabled = $enabled;
    }

    /**
     * Vérifie si le provider est activé
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Génère un lien de paiement PayTech
     * 
     * @param Order $order La commande à payer
     * @param string $paymentMethod Méthode de paiement (wave, orange_money, free_money, card)
     * @return array Résultat avec l'URL de paiement ou erreur
     */
    public function createPayment(Order $order, string $paymentMethod = 'all'): array
    {
        try {
            $client = $order->getClient();
            
            // Préparer les données du paiement
            $paymentData = [
                'item_name' => 'Paiement PlanB #' . $order->getId(),
                'item_price' => (int) $order->getAmount(),
                'currency' => 'XOF',
                'ref_command' => 'PLANB-' . $order->getId() . '-' . time(),
                'command_name' => 'Commande PlanB',
                'env' => $this->env,
                'ipn_url' => $this->ipnUrl,
                'success_url' => $this->successUrl . '?order_id=' . $order->getId(),
                'cancel_url' => $this->cancelUrl . '?order_id=' . $order->getId(),
                'custom_field' => json_encode([
                    'order_id' => $order->getId(),
                    'user_id' => $client->getId(),
                    'payment_method' => $paymentMethod
                ])
            ];

            $this->logger->info('PayTech: Création paiement', [
                'order_id' => $order->getId(),
                'amount' => $order->getAmount(),
                'method' => $paymentMethod
            ]);

            // Appel API PayTech
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'API_KEY' => $this->apiKey,
                    'API_SECRET' => $this->secretKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $paymentData,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($statusCode === 200 && isset($content['success']) && $content['success'] === 1) {
                $this->logger->info('PayTech: Paiement créé avec succès', [
                    'order_id' => $order->getId(),
                    'redirect_url' => $content['redirect_url'] ?? null
                ]);

                return [
                    'success' => true,
                    'payment_url' => $content['redirect_url'] ?? null,
                    'token' => $content['token'] ?? null,
                    'ref_command' => $paymentData['ref_command']
                ];
            }

            $this->logger->error('PayTech: Échec création paiement', [
                'order_id' => $order->getId(),
                'response' => $content
            ]);

            return [
                'success' => false,
                'error' => $content['message'] ?? 'Erreur lors de la création du paiement'
            ];

        } catch (\Exception $e) {
            $this->logger->error('PayTech: Exception', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erreur de connexion à PayTech: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Génère un lien de paiement pour une réservation (BookingPayment)
     */
    public function createBookingPayment(
        int $paymentId,
        float $amount,
        string $description,
        ?User $user = null
    ): array {
        try {
            $refCommand = 'PLANB-BK-' . $paymentId . '-' . time();
            
            $paymentData = [
                'item_name' => $description,
                'item_price' => (int) $amount,
                'currency' => 'XOF',
                'ref_command' => $refCommand,
                'command_name' => 'Réservation PlanB',
                'env' => $this->env,
                'ipn_url' => $this->ipnUrl,
                'success_url' => $this->successUrl . '?payment_id=' . $paymentId,
                'cancel_url' => $this->cancelUrl . '?payment_id=' . $paymentId,
                'custom_field' => json_encode([
                    'payment_id' => $paymentId,
                    'user_id' => $user?->getId(),
                    'type' => 'booking'
                ])
            ];

            $this->logger->info('PayTech: Création paiement réservation', [
                'payment_id' => $paymentId,
                'amount' => $amount
            ]);

            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'API_KEY' => $this->apiKey,
                    'API_SECRET' => $this->secretKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $paymentData,
            ]);

            $content = $response->toArray(false);

            if ($response->getStatusCode() === 200 && isset($content['success']) && $content['success'] === 1) {
                return [
                    'success' => true,
                    'payment_url' => $content['redirect_url'] ?? null,
                    'token' => $content['token'] ?? null,
                    'ref_command' => $refCommand
                ];
            }

            return [
                'success' => false,
                'error' => $content['message'] ?? 'Erreur PayTech'
            ];

        } catch (\Exception $e) {
            $this->logger->error('PayTech: Exception booking payment', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Génère un lien de paiement pour un abonnement PRO
     */
    public function createSubscriptionPayment(User $user, int $durationMonths = 1): array
    {
        try {
            $prices = [
                1 => 5000,
                3 => 12000,
                6 => 22000,
                12 => 40000
            ];

            $amount = $prices[$durationMonths] ?? 5000;
            $refCommand = 'PLANB-SUB-' . $user->getId() . '-' . $durationMonths . 'M-' . time();
            
            $paymentData = [
                'item_name' => 'Abonnement PRO PlanB - ' . $durationMonths . ' mois',
                'item_price' => $amount,
                'currency' => 'XOF',
                'ref_command' => $refCommand,
                'command_name' => 'Abonnement PRO',
                'env' => $this->env,
                'ipn_url' => $this->ipnUrl,
                'success_url' => $this->successUrl . '?subscription=true&months=' . $durationMonths,
                'cancel_url' => $this->cancelUrl . '?subscription=true',
                'custom_field' => json_encode([
                    'user_id' => $user->getId(),
                    'type' => 'subscription',
                    'duration_months' => $durationMonths
                ])
            ];

            $this->logger->info('PayTech: Création paiement abonnement', [
                'user_id' => $user->getId(),
                'duration' => $durationMonths,
                'amount' => $amount
            ]);

            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'API_KEY' => $this->apiKey,
                    'API_SECRET' => $this->secretKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $paymentData,
            ]);

            $content = $response->toArray(false);

            if ($response->getStatusCode() === 200 && isset($content['success']) && $content['success'] === 1) {
                return [
                    'success' => true,
                    'payment_url' => $content['redirect_url'] ?? null,
                    'token' => $content['token'] ?? null,
                    'ref_command' => $refCommand,
                    'amount' => $amount
                ];
            }

            return [
                'success' => false,
                'error' => $content['message'] ?? 'Erreur PayTech'
            ];

        } catch (\Exception $e) {
            $this->logger->error('PayTech: Exception subscription', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Vérifie la signature d'un webhook IPN PayTech
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        // PayTech envoie une signature basée sur le hash des données
        $expectedSignature = hash('sha256', json_encode($payload) . $this->secretKey);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Traite une notification IPN de PayTech
     */
    public function processIpnNotification(array $data): array
    {
        $this->logger->info('PayTech: IPN reçu', $data);

        $refCommand = $data['ref_command'] ?? null;
        $typeEvent = $data['type_event'] ?? null;
        $customField = isset($data['custom_field']) ? json_decode($data['custom_field'], true) : [];

        // Statuts PayTech: sale_complete, sale_canceled, sale_pending
        $status = match ($typeEvent) {
            'sale_complete' => 'completed',
            'sale_canceled' => 'cancelled',
            'sale_pending' => 'pending',
            default => 'unknown'
        };

        return [
            'ref_command' => $refCommand,
            'status' => $status,
            'type_event' => $typeEvent,
            'custom_field' => $customField,
            'amount' => $data['item_price'] ?? 0,
            'payment_method' => $data['payment_method'] ?? 'paytech'
        ];
    }
}
