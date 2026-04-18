<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'envoi de SMS via Twilio ou autre provider
 * Configuration dans .env : SMS_PROVIDER, TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM
 */
class SMSService
{
    private string $provider;
    private string $twilioSid;
    private string $twilioToken;
    private string $twilioFrom;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
        // Configuration depuis .env
        $this->provider = $_ENV['SMS_PROVIDER'] ?? 'twilio';
        $this->twilioSid = $_ENV['TWILIO_SID'] ?? '';
        $this->twilioToken = $_ENV['TWILIO_TOKEN'] ?? '';
        $this->twilioFrom = $_ENV['TWILIO_FROM'] ?? '';
    }

    /**
     * Envoyer un SMS
     */
    public function send(string $to, string $message): bool
    {
        try {
            if ($this->provider === 'twilio') {
                return $this->sendViaTwilio($to, $message);
            }
            
            // Autres providers peuvent être ajoutés ici
            // elseif ($this->provider === 'africell') { ... }
            
            $this->logger->warning('SMS provider not configured, SMS not sent', [
                'to' => $to,
                'message' => $message
            ]);
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send SMS', [
                'error' => $e->getMessage(),
                'to' => $to
            ]);
            return false;
        }
    }

    /**
     * Envoyer un code OTP
     */
    public function sendOTP(string $phone, string $code): bool
    {
        $message = "Votre code de vérification Plan B est : $code\nValide pendant 5 minutes.";
        return $this->send($phone, $message);
    }

    /**
     * Envoyer via Twilio
     */
    private function sendViaTwilio(string $to, string $message): bool
    {
        // Vérifier si les credentials sont configurés (pas des placeholders)
        $isConfigured = !empty($this->twilioSid) 
            && !empty($this->twilioToken) 
            && !str_starts_with($this->twilioSid, 'your_')
            && !str_starts_with($this->twilioToken, 'your_');
            
        if (!$isConfigured) {
            $this->logger->warning('Twilio credentials not configured');
            
            // En développement, simuler l'envoi
            if ($_ENV['APP_ENV'] === 'dev') {
                $this->logger->info('DEV MODE: SMS simulated', [
                    'to' => $to,
                    'message' => $message
                ]);
                return true;
            }
            
            return false;
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilioSid}/Messages.json";

        $response = $this->httpClient->request('POST', $url, [
            'auth_basic' => [$this->twilioSid, $this->twilioToken],
            'body' => [
                'From' => $this->twilioFrom,
                'To' => $to,
                'Body' => $message
            ]
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 201) {
            $this->logger->info('SMS sent successfully', ['to' => $to]);
            return true;
        }

        $this->logger->error('Failed to send SMS via Twilio', [
            'statusCode' => $statusCode,
            'to' => $to
        ]);

        return false;
    }

    /**
     * Générer un code OTP à 6 chiffres
     */
    public function generateOTP(): string
    {
        return sprintf("%06d", mt_rand(0, 999999));
    }

    /**
     * Valider le format d'un numéro de téléphone
     */
    public function validatePhoneNumber(string $phone): bool
    {
        // Format attendu : +[code pays][numéro]
        // Ex: +225070000000, +229070000000, +221070000000, +223070000000
        return preg_match('/^\+[0-9]{10,15}$/', $phone) === 1;
    }
}
