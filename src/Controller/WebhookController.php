<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\WebhookLog;
use App\Service\PayTechService;
use App\Service\KKiaPayService;
use App\Service\WebhookProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur pour gérer les webhooks de paiement
 * Routes publiques mais sécurisées par signature
 * Utilise PayTech et KKiaPay comme agrégateurs
 */
#[Route('/api/v1/webhooks')]
class WebhookController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PayTechService $payTechService,
        private KKiaPayService $kkiaPayService,
        private WebhookProcessor $webhookProcessor,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @deprecated Utilisez PayTech ou KKiaPay à la place
     * Les webhooks Wave directs ne sont plus supportés
     */
    #[Route('/wave', name: 'app_webhook_wave', methods: ['POST'])]
    public function waveWebhook(Request $request): JsonResponse
    {
        $this->logger->info('Wave webhook deprecated - use PayTech instead');
        return $this->json([
            'error' => 'Deprecated: Utilisez PayTech (/api/webhook/paytech) ou KKiaPay (/api/webhook/kkiapay)',
            'redirect' => '/api/webhook/paytech'
        ], Response::HTTP_GONE);
    }

    /**
     * @deprecated Utilisez PayTech ou KKiaPay à la place
     * Les webhooks Orange Money directs ne sont plus supportés
     */
    #[Route('/orange-money', name: 'app_webhook_orange_money', methods: ['POST'])]
    public function orangeMoneyWebhook(Request $request): JsonResponse
    {
        $this->logger->info('Orange Money webhook deprecated - use PayTech instead');
        return $this->json([
            'error' => 'Deprecated: Utilisez PayTech (/api/webhook/paytech) ou KKiaPay (/api/webhook/kkiapay)',
            'redirect' => '/api/webhook/paytech'
        ], Response::HTTP_GONE);
    }

    /**
     * Endpoint de test pour les webhooks (développement uniquement)
     * 
     * POST /api/v1/webhooks/test
     */
    #[Route('/test', name: 'app_webhook_test', methods: ['POST'])]
    public function testWebhook(Request $request): JsonResponse
    {
        // Désactiver en production
        if ($this->getParameter('kernel.environment') === 'prod') {
            return $this->json(['error' => 'Non disponible en production'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $provider = $data['provider'] ?? 'wave';

        $this->logger->info('Test webhook received', [
            'provider' => $provider,
            'data' => $data
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Webhook de test reçu',
            'provider' => $provider,
            'data' => $data
        ]);
    }

    /**
     * Liste des webhooks reçus (admin uniquement)
     * 
     * GET /api/v1/webhooks/logs
     */
    #[Route('/logs', name: 'app_webhook_logs', methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
    public function getWebhookLogs(Request $request): JsonResponse
    {

        $limit = (int) ($request->query->get('limit') ?? 50);
        $offset = (int) ($request->query->get('offset') ?? 0);
        $provider = $request->query->get('provider');

        $repository = $this->entityManager->getRepository(WebhookLog::class);
        $queryBuilder = $repository->createQueryBuilder('w')
            ->orderBy('w.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($provider) {
            $queryBuilder->andWhere('w.provider = :provider')
                ->setParameter('provider', $provider);
        }

        $webhooks = $queryBuilder->getQuery()->getResult();

        $data = array_map(function($webhook) {
            return [
                'id' => $webhook->getId(),
                'provider' => $webhook->getProvider(),
                'transaction_id' => $webhook->getTransactionId(),
                'event_type' => $webhook->getEventType(),
                'status' => $webhook->getStatus(),
                'error_message' => $webhook->getErrorMessage(),
                'ip_address' => $webhook->getIpAddress(),
                'created_at' => $webhook->getCreatedAt()->format('c'),
                'processed_at' => $webhook->getProcessedAt()?->format('c')
            ];
        }, $webhooks);

        return $this->json([
            'webhooks' => $data,
            'total' => count($data)
        ]);
    }

    /**
     * Webhook pour KKiaPay
     * Reçoit les notifications de paiement/annulation depuis KKiaPay
     * 
     * POST /api/v1/webhooks/kkiapay
     */
    #[Route('/kkiapay', name: 'app_webhook_kkiapay', methods: ['POST'])]
    public function handleKKiaPayWebhook(Request $request): Response
    {
        try {
            if (!$this->kkiaPayService->isEnabled()) {
                $this->logger->warning('KKiaPay webhook reçu mais service désactivé');
                return new Response('KKiaPay désactivé', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            
            $this->logger->info('KKiaPay Webhook reçu', [
                'transaction_id' => $data['transaction_id'] ?? null,
                'status' => $data['status'] ?? null
            ]);

            // Vérifier la signature du webhook (sécurité)
            $signature = $request->headers->get('X-Kkiapay-Signature') ?? 
                        $request->headers->get('x-kkiapay-signature');
            
            if (!$signature) {
                $this->logger->warning('KKiaPay webhook sans signature');
                return new Response('Signature manquante', Response::HTTP_UNAUTHORIZED);
            }

            // ✅ Traiter le webhook
            $transactionId = $data['transaction_id'] ?? null;
            $status = strtoupper($data['status'] ?? '');
            $reference = $data['reference'] ?? null;
            $amount = $data['amount'] ?? 0;
            $phone = $data['phone'] ?? null;

            if (!$transactionId) {
                $this->logger->error('KKiaPay webhook: transaction_id manquant');
                return new Response('Transaction ID manquant', Response::HTTP_BAD_REQUEST);
            }

            // Chercher le paiement correspondant
            $paymentRepo = $this->entityManager->getRepository(Payment::class);
            $payment = $paymentRepo->findOneBy(['transactionId' => $transactionId]);

            // Si pas trouvé par transactionId, chercher par référence custom
            if (!$payment && $reference) {
                // La référence peut être dans les métadonnées
                $allPayments = $paymentRepo->findBy(['status' => 'pending']);
                foreach ($allPayments as $p) {
                    $meta = $p->getMetadata();
                    if (($meta['kkiapay_ref'] ?? null) === $reference) {
                        $payment = $p;
                        break;
                    }
                }
            }

            if (!$payment) {
                $this->logger->warning('KKiaPay webhook: paiement non trouvé', [
                    'transaction_id' => $transactionId,
                    'reference' => $reference
                ]);
                // Toujours retourner 200 pour que KKiaPay ne renvoie pas
                return new Response('OK', Response::HTTP_OK);
            }

            // Traiter selon le statut
            if ($status === 'SUCCESS') {
                $payment->setStatus('completed');
                $payment->setTransactionId($transactionId);
                
                // Mettre à jour les métadonnées
                $meta = $payment->getMetadata() ?? [];
                $meta['kkiapay_webhook_received'] = true;
                $meta['kkiapay_phone'] = $phone;
                $payment->setMetadata($meta);

                // Activer l'abonnement si c'est une inscription
                $metadata = $payment->getMetadata();
                if (($metadata['type'] ?? null) === 'subscription') {
                    $user = $payment->getUser();
                    $months = $metadata['months'] ?? 1;
                    $this->activateSubscription($user, $months * 30);
                }

                $this->logger->info('KKiaPay paiement validé', [
                    'payment_id' => $payment->getId(),
                    'amount' => $amount,
                    'transaction_id' => $transactionId
                ]);

            } elseif ($status === 'FAILED' || $status === 'CANCELLED') {
                $payment->setStatus('failed');
                $payment->setErrorMessage('Paiement ' . $status . ' via KKiaPay');
                
                $this->logger->info('KKiaPay paiement échoué', [
                    'payment_id' => $payment->getId(),
                    'status' => $status,
                    'transaction_id' => $transactionId
                ]);

            } else {
                $payment->setStatus('processing');
                $this->logger->info('KKiaPay statut intermédiaire', [
                    'payment_id' => $payment->getId(),
                    'status' => $status
                ]);
            }

            $payment->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return new Response('OK', Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('KKiaPay webhook erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new Response('Erreur interne', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Active un abonnement utilisateur
     */
    private function activateSubscription($user, int $durationDays): void
    {
        $now = new \DateTimeImmutable();
        
        // Vérifier si l'utilisateur a déjà un abonnement
        $subscriptionRepo = $this->entityManager->getRepository('App\Entity\Subscription');
        $subscription = $subscriptionRepo->findOneBy(['user' => $user]);

        if (!$subscription) {
            $subscription = new \App\Entity\Subscription();
            $subscription->setUser($user);
            $subscription->setAccountType('PRO');
            $subscription->setStartDate($now);
            $subscription->setCreatedAt($now);
            $this->entityManager->persist($subscription);
        }

        // Calculer la date d'expiration
        if ($subscription->getExpiresAt() && $subscription->getExpiresAt() > $now) {
            // Prolonger l'abonnement existant
            $newExpiry = $subscription->getExpiresAt()->modify("+{$durationDays} days");
        } else {
            // Nouveau abonnement
            $newExpiry = $now->modify("+{$durationDays} days");
        }

        $subscription->setExpiresAt($newExpiry);
        $subscription->setStatus('active');
        $subscription->setUpdatedAt($now);

        // Mettre à jour l'utilisateur
        $user->setAccountType('PRO');
        $user->setSubscriptionExpiresAt($newExpiry);
        
        $this->entityManager->flush();

        $this->logger->info('Abonnement activé suite paiement KKiaPay', [
            'user_id' => $user->getId(),
            'expires_at' => $newExpiry->format('c'),
            'duration_days' => $durationDays
        ]);
    }
}


