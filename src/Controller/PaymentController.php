<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\PayTechService;
use App\Service\KKiaPayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/payments')]
class PaymentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PayTechService $payTechService,
        private KKiaPayService $kkiaPayService
    ) {}

    /**
     * Confirmer le paiement Wave et activer le compte PRO
     * Cette route est appelée quand l'utilisateur revient de Wave après paiement
     * SANS API Wave payante - basé sur la confiance (à vérifier manuellement)
     */
    #[Route('/confirm-wave', name: 'app_payment_confirm_wave', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function confirmWavePayment(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);
        
        // Récupérer les données du paiement
        $months = $data['months'] ?? 1;
        $amount = $data['amount'] ?? 10000;
        $phoneNumber = $data['phoneNumber'] ?? null;
        
        // Valider les données
        if ($months < 1 || $months > 12) {
            return $this->json(['error' => 'Durée invalide (1-12 mois)'], 400);
        }
        
        // Calculer la date d'expiration
        $durationDays = $months * 30;
        $startDate = new \DateTimeImmutable();
        
        // Si l'utilisateur a déjà un abonnement actif, prolonger
        $currentExpiry = $user->getSubscriptionExpiresAt();
        if ($currentExpiry && $currentExpiry > $startDate) {
            $expiresAt = $currentExpiry->modify("+{$durationDays} days");
        } else {
            $expiresAt = $startDate->modify("+{$durationDays} days");
        }

        // Créer un enregistrement de paiement (pour historique)
        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setCurrency('XOF');
        $payment->setPaymentMethod('wave_link');
        $payment->setStatus('pending_verification'); // À vérifier manuellement
        $payment->setDescription("Abonnement PRO {$months} mois via Wave Link");
        $payment->setMetadata([
            'months' => $months,
            'type' => 'subscription',
            'phone' => $phoneNumber,
            'needs_manual_verification' => true
        ]);
        $payment->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($payment);

        // Vérifier/créer l'abonnement
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

        // ✅ SECURITY: Ne PAS activer PRO immédiatement - attendre vérification manuelle
        $subscription->setStatus('pending_verification');
        $subscription->setExpiresAt($expiresAt);
        $subscription->setUpdatedAt(new \DateTimeImmutable());

        // NE PAS mettre à jour l'utilisateur en PRO avant vérification
        // L'activation sera faite par un admin via la validation du paiement

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Paiement enregistré, en attente de vérification',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'accountType' => $user->getAccountType(),
                'subscriptionExpiresAt' => $expiresAt->format('c')
            ],
            'subscription' => [
                'months' => $months,
                'amount' => $amount,
                'expiresAt' => $expiresAt->format('c'),
                'daysRemaining' => $durationDays,
                'status' => 'pending_verification'
            ],
            'payment' => [
                'id' => $payment->getId(),
                'status' => 'pending_verification',
                'note' => 'Paiement à vérifier dans les transactions Wave. Votre compte PRO sera activé après vérification.'
            ]
        ], 200);
    }

    /**
     * Créer un paiement pour abonnement PRO avec choix du moyen de paiement
     * Supporte: wave, orange_money, mtn_money, moov_money, card
     */
    #[Route('/create-subscription', name: 'app_payment_create_subscription', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createSubscriptionPayment(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);
        
        // Nombre de mois d'abonnement (1, 3, 6 ou 12)
        $months = $data['months'] ?? 1;
        if (!in_array($months, [1, 3, 6, 12])) {
            return $this->json(['error' => 'Durée invalide (1, 3, 6 ou 12 mois)'], 400);
        }

        // Méthode de paiement
        $paymentMethod = $data['paymentMethod'] ?? 'wave';
        $validMethods = ['wave', 'orange_money', 'mtn_money', 'moov_money', 'card'];
        if (!in_array($paymentMethod, $validMethods)) {
            return $this->json(['error' => 'Méthode de paiement invalide'], 400);
        }

        // Numéro de téléphone (requis pour mobile money)
        $phoneNumber = $data['phoneNumber'] ?? $user->getPhone();

        // Calcul du montant selon la durée
        $amounts = [
            1 => 5000,    // 5000 XOF pour 1 mois
            3 => 12000,   // 12000 XOF pour 3 mois (économie 3000)
            6 => 22000,   // 22000 XOF pour 6 mois (économie 8000)
            12 => 40000   // 40000 XOF pour 12 mois (économie 20000)
        ];
        $amount = $amounts[$months];
        $durationDays = $months * 30;

        // Créer l'enregistrement de paiement
        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setCurrency('XOF');
        $payment->setPaymentMethod($paymentMethod);
        $payment->setStatus('pending');
        $payment->setDescription("Abonnement PRO {$months} mois");
        $payment->setMetadata([
            'months' => $months,
            'duration_days' => $durationDays,
            'type' => 'subscription',
            'phone' => $phoneNumber
        ]);
        $payment->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        try {
            $result = $this->processPaymentByMethod($payment, $paymentMethod, $phoneNumber, $user);

            if (isset($result['error'])) {
                $payment->setStatus('failed');
                $payment->setErrorMessage($result['error']);
                $this->entityManager->flush();

                return $this->json([
                    'error' => $result['error'],
                    'details' => $result['details'] ?? null
                ], 400);
            }

            // Mettre à jour avec l'ID de transaction
            if (isset($result['transaction_id'])) {
                $payment->setTransactionId($result['transaction_id']);
            }
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'payment' => [
                    'id' => $payment->getId(),
                    'amount' => $amount,
                    'currency' => 'XOF',
                    'months' => $months,
                    'status' => 'pending',
                    'paymentMethod' => $paymentMethod,
                    'paymentUrl' => $result['payment_url'] ?? null,
                    'transactionId' => $result['transaction_id'] ?? null,
                    'ussdCode' => $result['ussd_code'] ?? null
                ],
                'message' => $result['message'] ?? 'Paiement initié'
            ], 201);

        } catch (\Exception $e) {
            $payment->setStatus('failed');
            $payment->setErrorMessage($e->getMessage());
            $this->entityManager->flush();

            return $this->json([
                'error' => 'Erreur lors de la création du paiement',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Traiter le paiement selon la méthode choisie
     * Utilise PayTech (Wave, Orange Money) ou KKiaPay (tous Mobile Money)
     */
    private function processPaymentByMethod(Payment $payment, string $method, ?string $phone, User $user): array
    {
        $amount = (float) $payment->getAmount();
        $paymentId = $payment->getId();
        $description = $payment->getDescription();

        switch ($method) {
            case 'wave':
            case 'orange_money':
            case 'paytech':
            case 'card':
                // Utiliser PayTech pour Wave, Orange Money et Carte bancaire
                $result = $this->payTechService->createBookingPayment(
                    $paymentId,
                    $amount,
                    $description,
                    $user
                );
                if (!$result['success']) {
                    return ['error' => $result['error'] ?? 'Erreur PayTech'];
                }
                return [
                    'transaction_id' => $result['ref_command'] ?? null,
                    'payment_url' => $result['payment_url'] ?? null,
                    'message' => 'Vous allez être redirigé vers PayTech pour le paiement'
                ];

            case 'mtn_money':
            case 'moov_money':
            case 'kkiapay':
                // Utiliser KKiaPay pour MTN et Moov
                return [
                    'kkiapay_config' => [
                        'publicKey' => $this->kkiaPayService->getPublicKey(),
                        'sandbox' => $this->kkiaPayService->isSandbox(),
                        'amount' => (int) $amount,
                        'name' => $user->getFirstName() . ' ' . $user->getLastName(),
                        'phone' => $phone ?? $user->getPhone(),
                        'email' => $user->getEmail()
                    ],
                    'payment_id' => $paymentId,
                    'message' => 'Utilisez le widget KKiaPay pour finaliser le paiement'
                ];

            default:
                return ['error' => 'Méthode de paiement non supportée. Utilisez: wave, orange_money, mtn_money, moov_money, card'];
        }
    }

    /**
     * @deprecated Callback MTN - Utilisez KKiaPay à la place
     */
    #[Route('/mtn/callback/{orderId}', name: 'app_payment_mtn_callback', methods: ['POST'])]
    public function mtnCallback(int $orderId, Request $request): JsonResponse
    {
        return $this->json(['error' => 'Deprecated: Utilisez KKiaPay', 'redirect' => '/api/webhook/kkiapay'], 410);
    }

    /**
     * @deprecated Callback Moov - Utilisez KKiaPay à la place
     */
    #[Route('/moov/callback/{orderId}', name: 'app_payment_moov_callback', methods: ['POST'])]
    public function moovCallback(int $orderId, Request $request): JsonResponse
    {
        return $this->json(['error' => 'Deprecated: Utilisez KKiaPay', 'redirect' => '/api/webhook/kkiapay'], 410);
    }

    /**
     * @deprecated Callback Orange Money - Utilisez PayTech à la place
     */
    #[Route('/orange-money/callback/{orderId}', name: 'app_payment_orange_callback', methods: ['POST'])]
    public function orangeMoneyCallback(int $orderId, Request $request): JsonResponse
    {
        return $this->json(['error' => 'Deprecated: Utilisez PayTech', 'redirect' => '/api/webhook/paytech'], 410);
    }

    /**
     * Traiter un paiement réussi (activer abonnement ou boost)
     */
    private function processSuccessfulPayment(Payment $payment): void
    {
        $metadata = $payment->getMetadata();
        $type = $metadata['type'] ?? null;

        if ($type === 'subscription') {
            $durationDays = $metadata['duration_days'] ?? ($metadata['months'] ?? 1) * 30;
            $this->activateSubscription($payment->getUser(), $durationDays);
        } elseif ($type === 'boost') {
            $listingId = $metadata['listing_id'] ?? null;
            if ($listingId) {
                $this->boostListingFeature($listingId);
            }
        }
    }

    /**
     * Obtenir les méthodes de paiement disponibles
     */
    #[Route('/methods', name: 'app_payment_methods', methods: ['GET'])]
    public function getPaymentMethods(): JsonResponse
    {
        return $this->json([
            'methods' => [
                [
                    'id' => 'wave',
                    'name' => 'Wave',
                    'description' => 'Paiement mobile Wave',
                    'countries' => ['SN', 'CI', 'ML', 'BF'],
                    'requiresPhone' => true,
                    'enabled' => true
                ],
                [
                    'id' => 'orange_money',
                    'name' => 'Orange Money',
                    'description' => 'Paiement mobile Orange',
                    'countries' => ['SN', 'CI', 'ML', 'BF', 'GN'],
                    'requiresPhone' => true,
                    'enabled' => true
                ],
                [
                    'id' => 'mtn_money',
                    'name' => 'MTN Mobile Money',
                    'description' => 'Paiement mobile MTN',
                    'countries' => ['CI', 'GH', 'CM', 'BJ'],
                    'requiresPhone' => true,
                    'enabled' => true
                ],
                [
                    'id' => 'moov_money',
                    'name' => 'Moov Money',
                    'description' => 'Paiement mobile Moov',
                    'countries' => ['CI', 'BF', 'BJ', 'TG'],
                    'requiresPhone' => true,
                    'enabled' => true
                ],
                [
                    'id' => 'card',
                    'name' => 'Carte Bancaire',
                    'description' => 'Visa, Mastercard',
                    'countries' => ['*'],
                    'requiresPhone' => false,
                    'enabled' => true
                ]
            ],
            'subscriptionPrices' => [
                1 => ['price' => 5000, 'label' => '1 mois'],
                3 => ['price' => 12000, 'label' => '3 mois', 'savings' => 3000],
                6 => ['price' => 22000, 'label' => '6 mois', 'savings' => 8000],
                12 => ['price' => 40000, 'label' => '12 mois', 'savings' => 20000]
            ]
        ]);
    }

    /**
     * Créer un paiement pour boost d'annonce
     */
    #[Route('/boost-listing', name: 'app_payment_boost_listing', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function boostListing(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $listingId = $data['listing_id'] ?? null;

        if (!$listingId) {
            return $this->json(['error' => 'listing_id requis'], 400);
        }

        // Vérifier que l'annonce appartient à l'utilisateur
        $listing = $this->entityManager->getRepository('App\Entity\Listing')->find($listingId);
        if (!$listing || $listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        // Montant du boost: 1000 XOF pour 7 jours de mise en avant
        $amount = 1000;

        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setCurrency('XOF');
        $payment->setPaymentMethod('mobile_money');
        $payment->setStatus('pending');
        $payment->setDescription("Boost annonce #{$listingId}");
        $payment->setMetadata([
            'listing_id' => $listingId,
            'type' => 'boost',
            'duration_days' => 7
        ]);
        $payment->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        try {
            $result = $this->payTechService->createBookingPayment(
                $payment->getId(),
                (float) $amount,
                "Boost annonce #{$listingId}",
                $user
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Erreur PayTech');
            }

            $payment->setTransactionId($result['ref_command'] ?? null);
            $this->entityManager->flush();

            return $this->json([
                'payment' => [
                    'id' => $payment->getId(),
                    'amount' => $amount,
                    'currency' => 'XOF',
                    'listing_id' => $listingId,
                    'status' => 'pending',
                    'payment_url' => $result['payment_url']
                ]
            ], 201);

        } catch (\Exception $e) {
            $payment->setStatus('failed');
            $payment->setErrorMessage($e->getMessage());
            $this->entityManager->flush();

            return $this->json([
                'error' => 'Erreur lors de la création du paiement',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Créer un paiement pour modification d'annonce (compte FREE uniquement)
     * 1000 FCFA par modification
     */
    #[Route('/edit-listing', name: 'app_payment_edit_listing', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function editListingPayment(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Les comptes PRO n'ont pas besoin de payer
        if ($user->getAccountType() === 'PRO') {
            return $this->json(['error' => 'Les comptes PRO peuvent modifier gratuitement'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $listingId = $data['listing_id'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? 'wave';
        $phoneNumber = $data['phoneNumber'] ?? $user->getPhone();

        if (!$listingId) {
            return $this->json(['error' => 'listing_id requis'], 400);
        }

        $validMethods = ['wave', 'orange_money', 'mtn_money', 'moov_money', 'card'];
        if (!in_array($paymentMethod, $validMethods)) {
            return $this->json(['error' => 'Méthode de paiement invalide'], 400);
        }

        // Vérifier que l'annonce appartient à l'utilisateur
        $listing = $this->entityManager->getRepository('App\Entity\Listing')->find($listingId);
        if (!$listing || $listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        // Les brouillons peuvent être modifiés gratuitement
        if ($listing->getStatus() === 'draft') {
            return $this->json(['error' => 'Les brouillons peuvent être modifiés gratuitement'], 400);
        }

        $amount = 1000; // 1000 FCFA par modification

        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setCurrency('XOF');
        $payment->setPaymentMethod($paymentMethod);
        $payment->setStatus('pending');
        $payment->setDescription("Modification annonce #{$listingId}");
        $payment->setMetadata([
            'listing_id' => $listingId,
            'type' => 'edit_listing',
            'phone' => $phoneNumber
        ]);
        $payment->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        try {
            $result = $this->processPaymentByMethod($payment, $paymentMethod, $phoneNumber, $user);

            if (isset($result['error'])) {
                $payment->setStatus('failed');
                $payment->setErrorMessage($result['error']);
                $this->entityManager->flush();

                return $this->json([
                    'error' => $result['error'],
                    'details' => $result['details'] ?? null
                ], 400);
            }

            if (isset($result['transaction_id'])) {
                $payment->setTransactionId($result['transaction_id']);
            }
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'payment' => [
                    'id' => $payment->getId(),
                    'amount' => $amount,
                    'currency' => 'XOF',
                    'listing_id' => $listingId,
                    'status' => 'pending',
                    'paymentMethod' => $paymentMethod,
                    'paymentUrl' => $result['payment_url'] ?? null,
                    'transactionId' => $result['transaction_id'] ?? null,
                    'ussdCode' => $result['ussd_code'] ?? null
                ],
                'message' => $result['message'] ?? 'Paiement initié'
            ], 201);

        } catch (\Exception $e) {
            $payment->setStatus('failed');
            $payment->setErrorMessage($e->getMessage());
            $this->entityManager->flush();

            return $this->json([
                'error' => 'Erreur lors de la création du paiement',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @deprecated Webhook Wave - Utilisez PayTech à la place
     */
    #[Route('/callback', name: 'app_payment_callback', methods: ['POST'])]
    public function waveCallback(Request $request): JsonResponse
    {
        return $this->json(['error' => 'Deprecated: Utilisez PayTech', 'redirect' => '/api/webhook/paytech'], 410);
    }

    /**
     * Vérifier le statut d'un paiement
     */
    #[Route('/{id}/status', name: 'app_payment_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getPaymentStatus(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payment = $this->entityManager->getRepository(Payment::class)->find($id);

        if (!$payment || $payment->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Paiement non trouvé'], 404);
        }

        // Pour les paiements en attente, le statut sera mis à jour via webhook PayTech/KKiaPay
        // Pas de vérification synchrone - les webhooks gèrent la confirmation

        return $this->json([
            'payment' => [
                'id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'status' => $payment->getStatus(),
                'description' => $payment->getDescription(),
                'createdAt' => $payment->getCreatedAt()->format('c'),
                'completedAt' => $payment->getCompletedAt()?->format('c')
            ]
        ]);
    }

    /**
     * Obtenir l'historique des paiements de l'utilisateur
     */
    #[Route('/history', name: 'app_payment_history', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getPaymentHistory(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payments = $this->entityManager->getRepository(Payment::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC'], 50);

        $data = array_map(function($payment) {
            return [
                'id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'status' => $payment->getStatus(),
                'description' => $payment->getDescription(),
                'createdAt' => $payment->getCreatedAt()->format('c'),
                'completedAt' => $payment->getCompletedAt()?->format('c')
            ];
        }, $payments);

        return $this->json(['payments' => $data]);
    }

    /**
     * Activer l'abonnement PRO
     */
    private function activateSubscription(User $user, int $durationDays): void
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

        $subscription->setStatus('active');
        $subscription->setExpiresAt($expiresAt);
        $subscription->setUpdatedAt(new \DateTimeImmutable());

        // Mettre à jour l'utilisateur
        $user->setAccountType('PRO');
        $user->setSubscriptionExpiresAt($expiresAt);
        $user->setUpdatedAt(new \DateTimeImmutable());
    }

    /**
     * Activer le boost d'une annonce
     */
    private function boostListingFeature(int $listingId): void
    {
        $listing = $this->entityManager->getRepository('App\Entity\Listing')->find($listingId);
        
        if ($listing) {
            $listing->setIsFeatured(true);
            $listing->setUpdatedAt(new \DateTimeImmutable());
            // Note: Ajouter un champ featuredUntil pour limiter la durée du boost
        }
    }
}
