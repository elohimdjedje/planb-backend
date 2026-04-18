<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Service d'envoi de SMS pour la 2FA
 * Abstrait le provider SMS — prêt pour Twilio ou tout autre fournisseur
 */
class SmsSender
{
    public function __construct(
        private SMSService $smsService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Envoyer un code OTP par SMS
     */
    public function sendOtp(string $phone, string $code): bool
    {
        if (empty($phone)) {
            $this->logger->warning('SmsSender: numéro de téléphone vide');
            return false;
        }

        $message = "Votre code de vérification Plan B est : {$code}\nCe code expire dans 5 minutes.\nNe le partagez avec personne.";

        try {
            $sent = $this->smsService->send($phone, $message);

            if ($sent) {
                $this->logger->info('SmsSender: OTP envoyé', [
                    'phone' => $this->maskPhone($phone),
                ]);
            } else {
                $this->logger->error('SmsSender: échec envoi OTP', [
                    'phone' => $this->maskPhone($phone),
                ]);
            }

            return $sent;
        } catch (\Exception $e) {
            $this->logger->error('SmsSender: exception lors de l\'envoi', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($phone),
            ]);
            return false;
        }
    }

    /**
     * Masquer le numéro de téléphone dans les logs
     */
    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 4) {
            return '****';
        }
        return substr($phone, 0, 3) . str_repeat('*', $len - 6) . substr($phone, -3);
    }
}
