<?php

namespace App\Controller\Api;

use App\Entity\SecureDeposit;
use App\Repository\DepositDisputeRepository;
use App\Repository\SecureDepositRepository;
use App\Service\DepositCertificateService;
use App\Service\SecureDepositService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API REST — Caution sécurisée (escrow) — Workflow complet
 *
 * Phase 1 — Signatures du contrat :
 *  POST   /api/v1/secure-deposits                     → Créer contrat + signature locataire
 *  POST   /api/v1/secure-deposits/{id}/sign-landlord  → Propriétaire signe
 *  POST   /api/v1/secure-deposits/{id}/sign-admin     → Admin signe → demande paiement
 *
 * Phase 2 — Paiement :
 *  POST   /api/v1/secure-deposits/{id}/confirm        → Confirmer paiement (locataire)
 *
 * Phase 3 — Restitution :
 *  POST   /api/v1/secure-deposits/{id}/request-termination → Locataire demande résiliation
 *  POST   /api/v1/secure-deposits/{id}/admin-review        → Admin traite
 *  POST   /api/v1/secure-deposits/{id}/landlord-inspect    → Propriétaire inspecte et signe
 *  POST   /api/v1/secure-deposits/{id}/tenant-exit-sign    → Locataire signe restitution
 *  POST   /api/v1/secure-deposits/{id}/admin-final-sign    → Admin signe final
 *  POST   /api/v1/secure-deposits/{id}/process-refund      → Traitement du remboursement
 *
 * Litiges :
 *  POST   /api/v1/secure-deposits/{id}/dispute              → Ouvrir litige
 *  POST   /api/v1/secure-deposits/{id}/dispute-respond      → Répondre au litige
 *  POST   /api/v1/secure-deposits/{id}/release              → Split release
 *
 * Autre :
 *  POST   /api/v1/secure-deposits/{id}/cancel               → Annuler contrat
 *  POST   /api/v1/secure-deposits/{id}/payout-methods       → Choisir moyen remboursement
 *  GET    /api/v1/secure-deposits/{id}/certificate          → PDF
 *  GET    /api/v1/secure-deposits/admin/stats               → Stats admin
 */
#[Route('/api/v1/secure-deposits')]
class SecureDepositController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecureDepositRepository $depositRepo,
        private DepositDisputeRepository $disputeRepo,
        private SecureDepositService $depositService,
        private DepositCertificateService $certificateService,
    ) {}

    // ── Liste des dépôts de l'utilisateur connecté ──────────
    #[Route('', name: 'api_sd_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $user     = $this->getUser();
        $deposits = $this->depositService->getByUser($user->getId());

        return $this->json([
            'success' => true,
            'data'    => array_map(
                fn(SecureDeposit $d) => $this->serializeDeposit($d),
                $deposits
            ),
        ]);
    }

    // ── Détail d'un dépôt ───────────────────────────────────
    #[Route('/{id}', name: 'api_sd_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function get(int $id): JsonResponse
    {
        $deposit = $this->depositRepo->find($id);
        if (!$deposit) {
            return $this->json(['error' => 'Dépôt introuvable'], Response::HTTP_NOT_FOUND);
        }

        $this->denyUnlessParty($deposit);

        return $this->json([
            'success' => true,
            'data'    => $this->serializeDeposit($deposit),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // PHASE 1 : CRÉATION ET SIGNATURES DU CONTRAT
    // ═════════════════════════════════════════════════════════

    /**
     * Le locataire crée le contrat et signe automatiquement.
     * Status : → signed_tenant
     */
    #[Route('', name: 'api_sd_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['listing_id'])) {
            return $this->json(['error' => 'listing_id requis'], Response::HTTP_BAD_REQUEST);
        }

        $listing = $this->entityManager->getRepository(\App\Entity\Listing::class)->find($data['listing_id']);
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $deposit = $this->depositService->createContract($listing, $this->getUser(), $data);

            return $this->json([
                'success' => true,
                'message' => 'Contrat créé et signé — en attente de la signature du propriétaire',
                'data'    => $this->serializeDeposit($deposit),
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Le propriétaire signe le contrat.
     * Status : signed_tenant → signed_landlord
     */
    #[Route('/{id}/sign-landlord', name: 'api_sd_sign_landlord', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function signLandlord(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessLandlord($deposit);

        try {
            $this->depositService->signByLandlord($deposit);

            return $this->json([
                'success' => true,
                'message' => 'Contrat signé par le propriétaire — en attente de la signature admin',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * L'admin signe le contrat → déclenche la demande de paiement au locataire.
     * Status : signed_landlord → pending_payment
     */
    #[Route('/{id}/sign-admin', name: 'api_sd_sign_admin', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function signAdmin(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        try {
            $this->depositService->signByAdmin($deposit);

            return $this->json([
                'success' => true,
                'message' => 'Contrat validé par l\'admin — demande de paiement envoyée au locataire',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // ═════════════════════════════════════════════════════════
    // PHASE 2 : PAIEMENT
    // ═════════════════════════════════════════════════════════

    /**
     * Confirmer paiement (webhook / simulation).
     * Status : pending_payment → active
     */
    #[Route('/{id}/confirm', name: 'api_sd_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function confirmPayment(int $id, Request $request): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessTenant($deposit);

        $data = json_decode($request->getContent(), true);

        try {
            $this->depositService->confirmPayment(
                $deposit,
                $data['transaction_id'] ?? 'TXN-' . uniqid(),
                $data['payment_provider'] ?? 'paytech',
                $data['payment_method'] ?? 'orange_money'
            );

            // Générer le certificat PDF
            $this->certificateService->generate($deposit);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Paiement confirmé — caution active',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // ═════════════════════════════════════════════════════════
    // PHASE 3 : RÉSILIATION ET RESTITUTION
    // ═════════════════════════════════════════════════════════

    /**
     * Le locataire demande la résiliation et récupération de caution.
     * Status : active → termination_requested
     */
    #[Route('/{id}/request-termination', name: 'api_sd_request_termination', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function requestTermination(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessTenant($deposit);

        try {
            $this->depositService->requestTermination($deposit);

            return $this->json([
                'success' => true,
                'message' => 'Demande de résiliation envoyée — en attente de traitement par l\'admin',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * L'admin traite la demande de résiliation et l'envoie au propriétaire.
     * Status : termination_requested → landlord_inspection
     */
    #[Route('/{id}/admin-review', name: 'api_sd_admin_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminReview(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        try {
            $this->depositService->adminReviewTermination($deposit);

            return $this->json([
                'success' => true,
                'message' => 'Demande traitée — envoyée au propriétaire pour inspection',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Le propriétaire inspecte le bien, ajoute ses notes et signe.
     * Status : landlord_inspection → landlord_validated
     */
    #[Route('/{id}/landlord-inspect', name: 'api_sd_landlord_inspect', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function landlordInspect(int $id, Request $request): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessLandlord($deposit);

        $data = json_decode($request->getContent(), true);
        $notes = $data['inspection_notes'] ?? null;

        try {
            $this->depositService->landlordInspectAndSign($deposit, $notes);

            return $this->json([
                'success' => true,
                'message' => 'Inspection validée et signée — en attente de la signature du locataire',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Le locataire signe le document de restitution.
     * Status : landlord_validated → tenant_exit_validated
     */
    #[Route('/{id}/tenant-exit-sign', name: 'api_sd_tenant_exit_sign', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function tenantExitSign(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessTenant($deposit);

        try {
            $this->depositService->tenantExitSign($deposit);

            return $this->json([
                'success' => true,
                'message' => 'Restitution signée — en attente de la validation finale admin',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * L'admin signe en dernier et déclenche le remboursement.
     * Status : tenant_exit_validated → refund_processing
     */
    #[Route('/{id}/admin-final-sign', name: 'api_sd_admin_final_sign', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminFinalSign(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        try {
            $this->depositService->adminFinalSign($deposit);

            return $this->json([
                'success' => true,
                'message' => 'Validation finale admin — remboursement en cours de traitement',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Traitement effectif du remboursement.
     * Status : refund_processing → completed
     */
    #[Route('/{id}/process-refund', name: 'api_sd_process_refund', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function processRefund(int $id, Request $request): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $data = json_decode($request->getContent(), true);

        try {
            $this->depositService->processRefund(
                $deposit,
                $data['refund_transaction_id'] ?? null
            );

            return $this->json([
                'success' => true,
                'message' => 'Remboursement effectué — caution terminée',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // ═════════════════════════════════════════════════════════
    // LITIGES
    // ═════════════════════════════════════════════════════════

    /**
     * Le propriétaire ouvre un litige (pendant l'inspection).
     */
    #[Route('/{id}/dispute', name: 'api_sd_dispute', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function dispute(int $id, Request $request): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessLandlord($deposit);

        $data = json_decode($request->getContent(), true);

        if (!isset($data['damage_description']) || !isset($data['estimated_cost'])) {
            return $this->json(
                ['error' => 'damage_description et estimated_cost sont requis'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $dispute = $this->depositService->openDispute($deposit, $this->getUser(), $data);

            return $this->json([
                'success' => true,
                'message' => 'Litige ouvert — le locataire a 7 jours pour répondre',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Le locataire répond au litige.
     */
    #[Route('/{id}/dispute-respond', name: 'api_sd_dispute_respond', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function respondToDispute(int $id, Request $request): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessTenant($deposit);

        $data = json_decode($request->getContent(), true);

        $disputes = $this->disputeRepo->findPendingByDeposit($deposit->getId());
        if (empty($disputes)) {
            return $this->json(['error' => 'Aucun litige en attente'], Response::HTTP_NOT_FOUND);
        }

        $dispute = $disputes[0];
        $accepted = (bool) ($data['accepted'] ?? false);
        $comment  = $data['comment'] ?? null;

        $this->depositService->respondToDispute($dispute, $accepted, $comment);

        $msg = $accepted
            ? 'Litige accepté — déblocage des fonds en cours'
            : 'Litige refusé — délai de 7 jours avant remboursement automatique';

        return $this->json([
            'success' => true,
            'message' => $msg,
            'data'    => $this->serializeDeposit($deposit),
        ]);
    }

    /**
     * Déblocage des fonds split (pour litiges résolus).
     */
    #[Route('/{id}/release', name: 'api_sd_release', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function releaseFunds(int $id, Request $request): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $data = json_decode($request->getContent(), true);

        if (!isset($data['refund_tenant']) || !isset($data['release_landlord'])) {
            return $this->json(
                ['error' => 'refund_tenant et release_landlord requis'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $this->depositService->processSplitRelease(
                $deposit,
                (string) $data['refund_tenant'],
                (string) $data['release_landlord'],
                $data['refund_transaction_id'] ?? null,
                $data['payout_transaction_id'] ?? null
            );

            return $this->json([
                'success' => true,
                'message' => 'Fonds débloqués',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // ═════════════════════════════════════════════════════════
    // UTILITAIRES
    // ═════════════════════════════════════════════════════════

    /**
     * Annuler un contrat (avant paiement).
     */
    #[Route('/{id}/cancel', name: 'api_sd_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessParty($deposit);

        try {
            $this->depositService->cancelContract($deposit);

            return $this->json([
                'success' => true,
                'message' => 'Contrat annulé',
                'data'    => $this->serializeDeposit($deposit),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Choisir moyen de paiement pour déblocage.
     */
    #[Route('/{id}/payout-methods', name: 'api_sd_payout_methods', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function setPayoutMethods(int $id, Request $request): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessParty($deposit);

        $data = json_decode($request->getContent(), true);

        $this->depositService->setPayoutMethods(
            $deposit,
            $data['tenant_method'] ?? null,
            $data['landlord_method'] ?? null,
        );

        return $this->json([
            'success' => true,
            'message' => 'Moyens de paiement enregistrés',
        ]);
    }

    /**
     * Télécharger le certificat PDF.
     */
    #[Route('/{id}/certificate', name: 'api_sd_certificate', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function certificate(int $id): JsonResponse
    {
        $deposit = $this->findDeposit($id);
        if ($deposit instanceof JsonResponse) return $deposit;

        $this->denyUnlessParty($deposit);

        $url = $deposit->getCertificatePdfUrl();
        if (!$url) {
            $url = $this->certificateService->generate($deposit);
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => true,
            'data'    => ['certificate_url' => $url],
        ]);
    }

    /**
     * Stats admin.
     */
    #[Route('/admin/stats', name: 'api_sd_admin_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminStats(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'data'    => $this->depositService->getAdminStats(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // HELPERS
    // ═════════════════════════════════════════════════════════

    private function findDeposit(int $id): SecureDeposit|JsonResponse
    {
        $deposit = $this->depositRepo->find($id);
        if (!$deposit) {
            return $this->json(['error' => 'Dépôt introuvable'], Response::HTTP_NOT_FOUND);
        }
        return $deposit;
    }

    private function denyUnlessParty(SecureDeposit $deposit): void
    {
        $userId = $this->getUser()->getId();
        if ($deposit->getTenant()->getId() !== $userId
            && $deposit->getLandlord()->getId() !== $userId
            && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Accès non autorisé');
        }
    }

    private function denyUnlessLandlord(SecureDeposit $deposit): void
    {
        if ($this->getUser()->getId() !== $deposit->getLandlord()->getId()
            && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Seul le bailleur peut effectuer cette action');
        }
    }

    private function denyUnlessTenant(SecureDeposit $deposit): void
    {
        if ($this->getUser()->getId() !== $deposit->getTenant()->getId()
            && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Seul le locataire peut effectuer cette action');
        }
    }

    private function serializeDeposit(SecureDeposit $deposit): array
    {
        return $this->depositService->serialize($deposit, $this->getUser()?->getId());
    }
}

