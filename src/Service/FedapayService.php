<?php

namespace App\Service;

/**
 * Service d'intégration Fedapay pour paiements Mobile Money
 * Documentation: https://docs.fedapay.com
 */
class FedapayService
{
    private string $apiKey;
    private string $environment;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = $_ENV['FEDAPAY_SECRET_KEY'] ?? '';
        $this->environment = $_ENV['FEDAPAY_ENVIRONMENT'] ?? 'sandbox'; // sandbox ou live
        $this->baseUrl = $this->environment === 'live' 
            ? 'https://api.fedapay.com/v1'
            : 'https://sandbox-api.fedapay.com/v1';
    }

    /**
     * Créer une transaction Fedapay
     * 
     * @param int $amount Montant en XOF
     * @param string $description Description du paiement
     * @param array $customer Informations client ['firstname', 'lastname', 'email', 'phone']
     * @return array Détails de la transaction
     */
    public function createTransaction(int $amount, string $description, array $customer): array
    {
        $data = [
            'description' => $description,
            'amount' => $amount,
            'currency' => [
                'iso' => 'XOF' // Franc CFA
            ],
            'callback_url' => $_ENV['APP_URL'] . '/api/v1/payments/callback',
            'customer' => [
                'firstname' => $customer['firstname'],
                'lastname' => $customer['lastname'],
                'email' => $customer['email'],
                'phone_number' => [
                    'number' => $customer['phone'],
                    'country' => 'bj' // Bénin par défaut, adapter selon le pays
                ]
            ]
        ];

        $response = $this->makeRequest('POST', '/transactions', $data);

        return [
            'transaction_id' => $response['id'] ?? null,
            'payment_url' => $response['url'] ?? null,
            'status' => $response['status'] ?? 'pending'
        ];
    }

    /**
     * Vérifier le statut d'une transaction
     * 
     * @param string $transactionId ID de la transaction Fedapay
     * @return array Statut de la transaction
     */
    public function getTransactionStatus(string $transactionId): array
    {
        $response = $this->makeRequest('GET', "/transactions/{$transactionId}");

        return [
            'id' => $response['id'] ?? null,
            'status' => $response['status'] ?? 'unknown',
            'amount' => $response['amount'] ?? 0,
            'currency' => $response['currency']['iso'] ?? 'XOF',
            'completed_at' => $response['approved_at'] ?? null,
            'customer' => $response['customer'] ?? []
        ];
    }

    /**
     * Vérifier le webhook de Fedapay
     * 
     * @param string $payload Payload JSON du webhook
     * @param string $signature Signature HTTP header
     * @return bool
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        $webhookSecret = $_ENV['FEDAPAY_WEBHOOK_SECRET'] ?? '';
        $computedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($computedSignature, $signature);
    }

    /**
     * Effectuer une requête à l'API Fedapay
     */
    private function makeRequest(string $method, string $endpoint, ?array $data = null): array
    {
        $ch = curl_init();

        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $error = json_decode($response, true);
            throw new \Exception($error['message'] ?? 'Erreur API Fedapay', $httpCode);
        }

        return json_decode($response, true);
    }

    /**
     * Calculer les frais de transaction
     * 
     * @param int $amount Montant en XOF
     * @return int Frais en XOF
     */
    public function calculateFees(int $amount): int
    {
        // Frais Fedapay: 1.5% + 100 XOF
        // https://fedapay.com/pricing
        $percentage = $amount * 0.015;
        $fixed = 100;
        
        return (int) ceil($percentage + $fixed);
    }
}
