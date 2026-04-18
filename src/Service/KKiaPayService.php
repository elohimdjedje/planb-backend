<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'intégration KKiaPay
 * Agrégateur de paiement Mobile Money (MTN, Orange, Moov, Wave)
 * Documentation: https://docs.kkiapay.me
 */
class KKiaPayService
{
    private const API_BASE_URL = 'https://api.kkiapay.me/api/v1';
    private const SANDBOX_URL = 'https://api-sandbox.kkiapay.me/api/v1';

    private string $publicKey;
    private string $privateKey;
    private string $secret;
    private bool $sandbox;
    private string $callbackUrl;
    private bool $enabled;
    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        string $kkiapayPublicKey,
        string $kkiapayPrivateKey,
        string $kkiapaySecret,
        bool $kkiapaySandbox = true,
        string $kkiapayCallbackUrl = '',
        bool $enabled = false
    ) {
        $this->httpClient = $httpClient;
        $this->publicKey = $kkiapayPublicKey;
        $this->privateKey = $kkiapayPrivateKey;
        $this->secret = $kkiapaySecret;
        $this->sandbox = $kkiapaySandbox;
        $this->callbackUrl = $kkiapayCallbackUrl;
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
     * Retourne l'URL de callback pour les webhooks
     */
    public function getCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

    /**
     * Retourne l'URL de base selon l'environnement
     */
    private function getBaseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::API_BASE_URL;
    }

    /**
     * Retourne la clé publique pour le frontend
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Vérifie si on est en mode sandbox
     */
    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * Vérifie une transaction par son ID
     * À appeler après un paiement pour confirmer côté backend
     */
    public function verifyTransaction(string $transactionId): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/transactions/' . $transactionId, [
                'headers' => [
                    'x-private-key' => $this->privateKey,
                    'x-secret-key' => $this->secret,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode === 200 && isset($data['status']) && $data['status'] === 'SUCCESS') {
                return [
                    'success' => true,
                    'transaction' => $data,
                    'amount' => $data['amount'] ?? 0,
                    'phone' => $data['client'] ?? null,
                    'status' => $data['status'],
                ];
            }

            return [
                'success' => false,
                'error' => $data['message'] ?? 'Transaction non trouvée ou échouée',
                'status' => $data['status'] ?? 'UNKNOWN',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Erreur lors de la vérification: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Initie un paiement (pour les paiements serveur-à-serveur)
     */
    public function initiatePayment(int $amount, string $phoneNumber, string $description = ''): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/payments/request', [
                'headers' => [
                    'x-private-key' => $this->privateKey,
                    'x-secret-key' => $this->secret,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'amount' => $amount,
                    'phone' => $phoneNumber,
                    'reason' => $description,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode === 200 || $statusCode === 201) {
                return [
                    'success' => true,
                    'transactionId' => $data['transactionId'] ?? null,
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => $data['message'] ?? 'Erreur lors de l\'initiation du paiement',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Erreur: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifie la signature d'un webhook KKiaPay
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $this->secret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Traite les données d'un webhook
     */
    public function processWebhook(array $data): array
    {
        $status = $data['status'] ?? null;
        $transactionId = $data['transactionId'] ?? null;
        $amount = $data['amount'] ?? 0;

        return [
            'transactionId' => $transactionId,
            'status' => $status,
            'amount' => $amount,
            'isSuccess' => $status === 'SUCCESS',
            'phone' => $data['client'] ?? null,
            'reason' => $data['reason'] ?? null,
        ];
    }
}
