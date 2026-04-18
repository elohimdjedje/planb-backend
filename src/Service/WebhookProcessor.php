<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\Subscription;
use App\Entity\WebhookLog;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service pour traiter les webhooks de paiement
 */
class WebhookProcessor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private EmailService $emailService
    ) {
    }

    /**
     * Traiter un webhook Wave
     * 
     * @param array $data Données du webhook
     * @param WebhookLog $webhookLog Log du webhook
     * @return array Résultat du traitement
     */
    public function processWaveWebhook(array $data, WebhookLog $webhookLog): array
    {
        try {
            $transactionId = $data['transaction']['id'] ?? $data['id'] ?? null;
            $status = $data['payment_status'] ?? $data['transaction']['status'] ?? $data['status'] ?? 'unknown';
            $amount = $data['amount'] ?? $data['transaction']['amount'] ?? null;
            $currency = $data['currency'] ?? $data['transaction']['currency'] ?? 'XOF';

            if (!$transactionId) {
                throw new \Exception('Transaction ID manquant');
            }

            // Trouver le paiement correspondant
            $payment = $this->entityManager->getRepository(Payment::class)
                ->findOneBy(['transactionId' => $transactionId]);

            if (!$payment) {
                // Essayer de trouver par client_reference
                $clientReference = $data['client_reference'] ?? $data['transaction']['client_reference'] ?? null;
                if ($clientReference) {
                    $payment = $this->entityManager->getRepository(Payment::class)
                        ->find((int) $clientReference);
                }
            }

            if (!$payment) {
                $this->logger->warning('Wave webhook: Payment not found', [
                    'transaction_id' => $transactionId,
                    'data' => $data
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Paiement non trouvé pour cette transaction'
                ];
            }

            // Vérifier que le montant correspond
            if ($amount && abs((float)$payment->getAmount() - (float)$amount) > 0.01) {
                $this->logger->warning('Wave webhook: Amount mismatch', [
                    'payment_amount' => $payment->getAmount(),
                    'webhook_amount' => $amount,
                    'transaction_id' => $transactionId
                ]);
            }

            // Mettre à jour le statut du paiement
            if (in_array($status, ['success', 'completed', 'paid'])) {
                if ($payment->getStatus() !== 'completed') {
                    $payment->setStatus('completed');
                    $payment->setCompletedAt(new \DateTimeImmutable());
                    
                    // Traiter selon le type de paiement
                    $metadata = $payment->getMetadata() ?? [];
                    $type = $metadata['type'] ?? null;

                    if ($type === 'subscription') {
                        $this->activateSubscription($payment->getUser(), $metadata['duration'] ?? 30);
                    } elseif ($type === 'boost') {
                        $this->boostListing($metadata['listing_id'] ?? null);
                    }

                    $this->entityManager->flush();

                    $this->logger->info('Wave payment completed', [
                        'payment_id' => $payment->getId(),
                        'transaction_id' => $transactionId,
                        'type' => $type
                    ]);
                }

                return [
                    'success' => true,
                    'payment_id' => $payment->getId(),
                    'status' => 'completed'
                ];

            } elseif (in_array($status, ['failed', 'cancelled', 'canceled', 'rejected'])) {
                $payment->setStatus('failed');
                $payment->setErrorMessage('Paiement refusé ou annulé: ' . $status);
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'payment_id' => $payment->getId(),
                    'status' => 'failed'
                ];
            }

            // Statut inconnu ou pending
            return [
                'success' => true,
                'payment_id' => $payment->getId(),
                'status' => $status
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error processing Wave webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Traiter un webhook Orange Money
     * 
     * @param array $data Données du webhook
     * @param WebhookLog $webhookLog Log du webhook
     * @return array Résultat du traitement
     */
    public function processOrangeMoneyWebhook(array $data, WebhookLog $webhookLog): array
    {
        try {
            $transactionId = $data['transaction_id'] ?? $data['order_id'] ?? $data['payment_token'] ?? null;
            $status = $data['status'] ?? $data['payment_status'] ?? 'unknown';
            $amount = $data['amount'] ?? null;
            $currency = $data['currency'] ?? 'XOF';

            if (!$transactionId) {
                throw new \Exception('Transaction ID manquant');
            }

            // Trouver le paiement
            $payment = $this->entityManager->getRepository(Payment::class)
                ->findOneBy(['transactionId' => $transactionId]);

            if (!$payment) {
                // Essayer par order_id
                $orderId = $data['order_id'] ?? null;
                if ($orderId) {
                    $payment = $this->entityManager->getRepository(Payment::class)
                        ->find((int) $orderId);
                }
            }

            if (!$payment) {
                $this->logger->warning('Orange Money webhook: Payment not found', [
                    'transaction_id' => $transactionId
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Paiement non trouvé'
                ];
            }

            // Mettre à jour le statut
            if (in_array($status, ['SUCCESS', 'COMPLETED', 'PAID', 'success', 'completed', 'paid'])) {
                if ($payment->getStatus() !== 'completed') {
                    $payment->setStatus('completed');
                    $payment->setCompletedAt(new \DateTimeImmutable());

                    $metadata = $payment->getMetadata() ?? [];
                    $type = $metadata['type'] ?? null;

                    if ($type === 'subscription') {
                        $this->activateSubscription($payment->getUser(), $metadata['duration'] ?? 30);
                    } elseif ($type === 'boost') {
                        $this->boostListing($metadata['listing_id'] ?? null);
                    }

                    $this->entityManager->flush();

                    $this->logger->info('Orange Money payment completed', [
                        'payment_id' => $payment->getId(),
                        'transaction_id' => $transactionId
                    ]);
                }

                return [
                    'success' => true,
                    'payment_id' => $payment->getId(),
                    'status' => 'completed'
                ];

            } elseif (in_array($status, ['FAILED', 'CANCELLED', 'REJECTED', 'failed', 'cancelled', 'rejected'])) {
                $payment->setStatus('failed');
                $payment->setErrorMessage('Paiement refusé: ' . $status);
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'payment_id' => $payment->getId(),
                    'status' => 'failed'
                ];
            }

            return [
                'success' => true,
                'payment_id' => $payment->getId(),
                'status' => $status
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error processing Orange Money webhook', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Activer un abonnement PRO
     */
    private function activateSubscription($user, int $durationDays): void
    {
        $startDate = new \DateTimeImmutable();
        $expiresAt = $startDate->modify("+{$durationDays} days");

        // Vérifier si l'utilisateur a déjà un abonnement
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $user]);

        if (!$subscription) {
            $subscription = new Subscription();
            $subscription->setUser($user);
            $subscription->setAccountType('PRO');
            $subscription->setStartDate($startDate);
            $subscription->setCreatedAt($startDate);
            $this->entityManager->persist($subscription);
        }

        // Prolonger l'abonnement si déjà actif
        $currentExpiry = $user->getSubscriptionExpiresAt();
        if ($currentExpiry && $currentExpiry > $startDate) {
            $expiresAt = $currentExpiry->modify("+{$durationDays} days");
        }

        $subscription->setStatus('active');
        $subscription->setExpiresAt($expiresAt);
        $subscription->setUpdatedAt(new \DateTimeImmutable());

        // Mettre à jour l'utilisateur
        $user->setAccountType('PRO');
        $user->setSubscriptionExpiresAt($expiresAt);
        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->logger->info('Subscription activated', [
            'user_id' => $user->getId(),
            'duration_days' => $durationDays,
            'expires_at' => $expiresAt->format('c')
        ]);

        // Envoyer un email de confirmation d'abonnement PRO
        try {
            $this->emailService->sendWelcomeEmail($user, ''); // Pas besoin de token pour PRO
        } catch (\Exception $e) {
            $this->logger->error('Failed to send subscription confirmation email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Activer le boost d'une annonce
     */
    private function boostListing(?int $listingId): void
    {
        if (!$listingId) {
            return;
        }

        $listing = $this->entityManager->getRepository('App\Entity\Listing')->find($listingId);
        
        if ($listing) {
            $listing->setIsFeatured(true);
            $listing->setUpdatedAt(new \DateTimeImmutable());
            
            $this->logger->info('Listing boosted', [
                'listing_id' => $listingId
            ]);
        }
    }
}


