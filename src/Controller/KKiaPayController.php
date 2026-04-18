<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\User;
use App\Service\KKiaPayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class KKiaPayController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private KKiaPayService $kkiaPayService
    ) {}

    /**
     * Retourne la configuration KKiaPay pour le frontend
     * GET /api/kkiapay/config
     */
    #[Route('/kkiapay/config', name: 'kkiapay_config', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        if (!$this->kkiaPayService->isEnabled()) {
            return $this->json([
                'enabled' => false,
                'message' => 'KKiaPay est désactivé. Utilisez PayTech.',
                'alternative' => '/api/paytech/create-payment'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'enabled' => true,
            'publicKey' => $this->kkiaPayService->getPublicKey(),
            'sandbox' => $this->kkiaPayService->isSandbox(),
        ]);
    }

    /**
     * Vérifie une transaction après paiement
     * POST /api/kkiapay/verify
     */
    #[Route('/kkiapay/verify', name: 'kkiapay_verify', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function verifyTransaction(Request $request): JsonResponse
    {
        if (!$this->kkiaPayService->isEnabled()) {
            return $this->json([
                'error' => 'KKiaPay est désactivé. Utilisez PayTech.',
                'alternative' => '/api/paytech/create-payment'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $data = json_decode($request->getContent(), true);
        $transactionId = $data['transactionId'] ?? null;
        $months = $data['months'] ?? 1;
        $type = $data['type'] ?? 'subscription'; // subscription, boost, etc.

        if (!$transactionId) {
            return $this->json(['error' => 'Transaction ID requis'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Vérifier la transaction auprès de KKiaPay
        $result = $this->kkiaPayService->verifyTransaction($transactionId);

        if (!$result['success']) {
            return $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Transaction non valide'
            ], Response::HTTP_BAD_REQUEST);
        }

        $amount = $result['amount'];

        // Créer un enregistrement de paiement
        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setCurrency('XOF');
        $payment->setPaymentMethod('kkiapay');
        $payment->setStatus('completed');
        $payment->setTransactionId($transactionId);
        $payment->setDescription("Paiement KKiaPay - {$type}");
        $payment->setMetadata([
            'transactionId' => $transactionId,
            'type' => $type,
            'months' => $months,
            'phone' => $result['phone'] ?? null,
        ]);

        $this->entityManager->persist($payment);

        // Si c'est un abonnement PRO
        if ($type === 'subscription') {
            $durationDays = $months * 30;
            $startDate = new \DateTimeImmutable();
            
            // Si l'utilisateur a déjà un abonnement actif, prolonger
            $currentExpiry = $user->getSubscriptionExpiresAt();
            if ($currentExpiry && $currentExpiry > $startDate) {
                $expiresAt = $currentExpiry->modify("+{$durationDays} days");
            } else {
                $expiresAt = $startDate->modify("+{$durationDays} days");
            }

            $user->setAccountType('PRO');
            $user->setSubscriptionStartDate($startDate);
            $user->setSubscriptionExpiresAt($expiresAt);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Paiement vérifié avec succès',
            'payment' => [
                'id' => $payment->getId(),
                'amount' => $amount,
                'status' => 'completed',
            ],
            'subscription' => $type === 'subscription' ? [
                'accountType' => $user->getAccountType(),
                'expiresAt' => $user->getSubscriptionExpiresAt()?->format('c'),
            ] : null,
        ]);
    }

    /**
     * Webhook KKiaPay pour recevoir les notifications de paiement
     * POST /api/webhook/kkiapay
     */
    #[Route('/webhook/kkiapay', name: 'kkiapay_webhook', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        if (!$this->kkiaPayService->isEnabled()) {
            return $this->json([
                'error' => 'KKiaPay est désactivé',
                'received' => false
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = $request->getContent();
        $signature = $request->headers->get('X-KKIAPAY-SIGNATURE', '');

        $data = json_decode($payload, true);
        
        if (!$data) {
            return $this->json(['error' => 'Données invalides'], Response::HTTP_BAD_REQUEST);
        }

        $webhookData = $this->kkiaPayService->processWebhook($data);

        error_log("KKiaPay Webhook: " . json_encode($webhookData));

        if ($webhookData['isSuccess']) {
            // Paiement réussi - chercher le paiement en base et mettre à jour
            $transactionId = $webhookData['transactionId'] ?? null;
            if ($transactionId) {
                $payment = $this->entityManager->getRepository(Payment::class)
                    ->findOneBy(['transactionId' => $transactionId]);

                if ($payment && $payment->getStatus() !== 'completed') {
                    $payment->setStatus('completed');
                    $payment->setCompletedAt(new \DateTimeImmutable());

                    // Activer l'abonnement PRO si c'est un paiement d'abonnement
                    $metadata = $payment->getMetadata();
                    if (($metadata['type'] ?? '') === 'subscription') {
                        $user = $payment->getUser();
                        $months = $metadata['months'] ?? 1;
                        $durationDays = $months * 30;

                        $currentExpiry = $user->getSubscriptionExpiresAt();
                        $now = new \DateTimeImmutable();
                        if ($currentExpiry && $currentExpiry > $now) {
                            $expiresAt = $currentExpiry->modify("+{$durationDays} days");
                        } else {
                            $expiresAt = $now->modify("+{$durationDays} days");
                        }

                        $user->setAccountType('PRO');
                        $user->setSubscriptionStartDate($now);
                        $user->setSubscriptionExpiresAt($expiresAt);
                    }

                    $this->entityManager->flush();
                    error_log("KKiaPay Webhook: Payment #{$payment->getId()} completed");
                }
            }
        }

        return $this->json(['received' => true]);
    }

    /**
     * Historique des paiements de l'utilisateur
     * GET /api/v1/kkiapay/history
     */
    #[Route('/kkiapay/history', name: 'kkiapay_history', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getPaymentHistory(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payments = $this->entityManager->getRepository(Payment::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC'], 20);

        return $this->json([
            'payments' => array_map(fn($p) => [
                'id' => $p->getId(),
                'amount' => $p->getAmount(),
                'currency' => $p->getCurrency(),
                'status' => $p->getStatus(),
                'method' => $p->getPaymentMethod(),
                'description' => $p->getDescription(),
                'createdAt' => $p->getCreatedAt()?->format('c'),
            ], $payments)
        ]);
    }
}
