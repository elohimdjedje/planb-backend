<?php

namespace App\Controller\Api;

use App\Entity\SaleContract;
use App\Repository\OfferRepository;
use App\Repository\SaleContractRepository;
use App\Service\KKiaPayService;
use App\Service\NotificationManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/sale-contracts')]
#[IsGranted('ROLE_USER')]
class SaleContractController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private OfferRepository $offerRepository,
        private SaleContractRepository $saleContractRepository,
        private KKiaPayService $kkiapayService,
        private NotificationManagerService $notificationManager
    ) {}

    // ── Lecture ───────────────────────────────────────────────

    /** GET /api/v1/sale-contracts/offer/{offerId} */
    #[Route('/offer/{offerId}', name: 'api_sale_contract_by_offer', methods: ['GET'], requirements: ['offerId' => '\d+'])]
    public function getByOffer(int $offerId): JsonResponse
    {
        $user  = $this->getUser();
        $offer = $this->offerRepository->find($offerId);
        if (!$offer) {
            return $this->json(['error' => 'Offre introuvable'], 404);
        }

        if ($offer->getBuyer()->getId() !== $user->getId() &&
            $offer->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        $sc = $this->saleContractRepository->findByOffer($offer);
        if (!$sc) {
            return $this->json(['error' => 'Contrat de vente introuvable'], 404);
        }

        return $this->json([
            'success' => true,
            'data'    => $sc->toArray(),
            'role'    => $sc->getBuyer()->getId() === $user->getId() ? 'buyer' : 'seller',
        ]);
    }

    /** GET /api/v1/sale-contracts/{id} */
    #[Route('/{id}', name: 'api_sale_contract_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $user = $this->getUser();
        $sc   = $this->saleContractRepository->find($id);
        if (!$sc) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }

        if ($sc->getBuyer()->getId() !== $user->getId() &&
            $sc->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        return $this->json([
            'success' => true,
            'data'    => $sc->toArray(),
            'role'    => $sc->getBuyer()->getId() === $user->getId() ? 'buyer' : 'seller',
        ]);
    }

    /** GET /api/v1/sale-contracts — mes contrats de vente */
    #[Route('', name: 'api_sale_contracts_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user      = $this->getUser();
        $contracts = $this->saleContractRepository->findByUser($user);

        $data = array_map(function (SaleContract $sc) use ($user) {
            $arr         = $sc->toArray();
            $arr['role'] = $sc->getBuyer()->getId() === $user->getId() ? 'buyer' : 'seller';
            return $arr;
        }, $contracts);

        return $this->json(['success' => true, 'data' => $data, 'total' => count($data)]);
    }

    // ── Signatures ────────────────────────────────────────────

    /** POST /api/v1/sale-contracts/{id}/sign-buyer */
    #[Route('/{id}/sign-buyer', name: 'api_sale_contract_sign_buyer', methods: ['POST'])]
    public function signBuyer(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $sc   = $this->saleContractRepository->find($id);
        if (!$sc) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }

        if ($sc->getBuyer()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul l\'acheteur peut signer en premier'], 403);
        }

        if ($sc->getStatus() !== SaleContract::STATUS_DRAFT) {
            return $this->json(['error' => 'Ce contrat ne peut plus être signé par l\'acheteur'], 409);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['signature_url'])) {
            return $this->json(['error' => 'signature_url est requis'], 400);
        }

        $sc->setBuyerSignedAt(new \DateTime());
        $sc->setBuyerSignatureUrl($data['signature_url']);
        $sc->setStatus(SaleContract::STATUS_BUYER_SIGNED);
        $this->em->flush();

        // Notifier le vendeur
        $this->notificationManager->createNotification(
            $sc->getSeller(),
            'sale_contract',
            'Compromis de vente signé par l\'acheteur',
            sprintf('L\'acheteur %s a signé le compromis de vente pour "%s". Veuillez le signer à votre tour.',
                $sc->getBuyer()->getFullName(),
                $sc->getListing()->getTitle()
            ),
            ['saleContractId' => $sc->getId()]
        );

        return $this->json(['success' => true, 'message' => 'Compromis signé par l\'acheteur', 'data' => $sc->toArray()]);
    }

    /** POST /api/v1/sale-contracts/{id}/sign-seller */
    #[Route('/{id}/sign-seller', name: 'api_sale_contract_sign_seller', methods: ['POST'])]
    public function signSeller(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $sc   = $this->saleContractRepository->find($id);
        if (!$sc) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }

        if ($sc->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le vendeur peut signer en second'], 403);
        }

        if ($sc->getStatus() !== SaleContract::STATUS_BUYER_SIGNED) {
            return $this->json(['error' => 'Le contrat doit d\'abord être signé par l\'acheteur'], 409);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['signature_url'])) {
            return $this->json(['error' => 'signature_url est requis'], 400);
        }

        $sc->setSellerSignedAt(new \DateTime());
        $sc->setSellerSignatureUrl($data['signature_url']);
        $sc->setLockedAt(new \DateTime());
        $sc->setStatus(SaleContract::STATUS_ESCROW_PENDING);
        $this->em->flush();

        // Notifier l'acheteur : passer au paiement
        $this->notificationManager->createNotification(
            $sc->getBuyer(),
            'sale_contract',
            'Compromis signé ✓ — Procédez au paiement sécurisé',
            sprintf('Le vendeur a signé le compromis pour "%s". Vous pouvez maintenant procéder au paiement sécurisé de %s XOF.',
                $sc->getListing()->getTitle(),
                number_format((float) $sc->getSalePrice(), 0, ',', ' ')
            ),
            ['saleContractId' => $sc->getId()]
        );

        return $this->json(['success' => true, 'message' => 'Compromis verrouillé — paiement requis', 'data' => $sc->toArray()]);
    }

    // ── Paiement séquestre ────────────────────────────────────

    /** GET /api/v1/sale-contracts/kkiapay-config */
    #[Route('/kkiapay-config', name: 'api_sale_contract_kkiapay_config', methods: ['GET'])]
    public function kkiapayConfig(): JsonResponse
    {
        return $this->json([
            'public_key'   => $this->kkiapayService->getPublicKey(),
            'is_sandbox'   => $this->kkiapayService->isSandbox(),
            'callback_url' => $this->kkiapayService->getCallbackUrl(),
        ]);
    }

    /** POST /api/v1/sale-contracts/{id}/confirm-payment */
    #[Route('/{id}/confirm-payment', name: 'api_sale_contract_confirm_payment', methods: ['POST'])]
    public function confirmPayment(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $sc   = $this->saleContractRepository->find($id);
        if (!$sc) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }

        if ($sc->getBuyer()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul l\'acheteur peut confirmer le paiement'], 403);
        }

        if ($sc->getStatus() !== SaleContract::STATUS_ESCROW_PENDING) {
            return $this->json(['error' => 'Ce contrat n\'attend pas de paiement'], 409);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['transaction_id'])) {
            return $this->json(['error' => 'transaction_id est requis'], 400);
        }

        // Vérifier la transaction KKiaPay
        try {
            $verified = $this->kkiapayService->verifyTransaction($data['transaction_id']);
            if (!$verified['success']) {
                return $this->json(['error' => 'Transaction KKiaPay invalide ou non confirmée'], 402);
            }
        } catch (\Exception $e) {
            // En mode sandbox/test, on accepte sans vérification
            if (!$this->kkiapayService->isSandbox()) {
                return $this->json(['error' => 'Impossible de vérifier la transaction : ' . $e->getMessage()], 402);
            }
        }

        $sc->setKkiapayTransactionId($data['transaction_id']);
        $sc->setPaidAt(new \DateTime());
        $sc->setPaymentStatus('escrow_success');
        $sc->setStatus(SaleContract::STATUS_ESCROW_FUNDED);
        $this->em->flush();

        // Notifier le vendeur que le paiement est sécurisé
        $this->notificationManager->createNotification(
            $sc->getSeller(),
            'sale_contract',
            'Paiement sécurisé reçu',
            sprintf('Le paiement de %s XOF pour "%s" est sécurisé sur la plateforme PlanB. Confirmez la remise des clés pour finaliser la vente.',
                number_format((float) $sc->getSalePrice(), 0, ',', ' '),
                $sc->getListing()->getTitle()
            ),
            ['saleContractId' => $sc->getId()]
        );

        return $this->json([
            'success' => true,
            'message' => 'Paiement sécurisé sur la plateforme',
            'data'    => $sc->toArray(),
        ]);
    }

    // ── Finalisation ──────────────────────────────────────────

    /** POST /api/v1/sale-contracts/{id}/complete — vendeur confirme la remise des clés */
    #[Route('/{id}/complete', name: 'api_sale_contract_complete', methods: ['POST'])]
    public function complete(int $id): JsonResponse
    {
        $user = $this->getUser();
        $sc   = $this->saleContractRepository->find($id);
        if (!$sc) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }

        if ($sc->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le vendeur peut finaliser la vente'], 403);
        }

        if ($sc->getStatus() !== SaleContract::STATUS_ESCROW_FUNDED) {
            return $this->json(['error' => 'Le paiement séquestre doit être confirmé avant finalisation'], 409);
        }

        $sc->setStatus(SaleContract::STATUS_COMPLETED);
        $sc->setCompletedAt(new \DateTime());

        // Passer le listing en statut "sold"
        $listing = $sc->getListing();
        $listing->setStatus('sold');

        $this->em->flush();

        // Notifier l'acheteur
        $this->notificationManager->createNotification(
            $sc->getBuyer(),
            'sale_contract',
            'Vente finalisée ! Félicitations 🎉',
            sprintf('Votre achat de "%s" est finalisé. Les fonds ont été transférés au vendeur.',
                $listing->getTitle()
            ),
            ['saleContractId' => $sc->getId()]
        );

        return $this->json([
            'success' => true,
            'message' => 'Vente finalisée avec succès',
            'data'    => $sc->toArray(),
        ]);
    }
}
