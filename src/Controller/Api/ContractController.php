<?php

namespace App\Controller\Api;

use App\Entity\Contract;
use App\Repository\BookingRepository;
use App\Repository\ContractAuditLogRepository;
use App\Repository\ContractRepository;
use App\Service\ContractService;
use App\Service\KKiaPayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/contracts')]
class ContractController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingRepository $bookingRepository,
        private ContractRepository $contractRepository,
        private ContractAuditLogRepository $auditLogRepository,
        private ContractService $contractService,
        private KKiaPayService $kkiapayService
    ) {}

    // ══════════════════════════════════════════════════════════════
    //  GÉNÉRATION
    // ══════════════════════════════════════════════════════════════

    /** POST /api/v1/contracts/generate */
    #[Route('/generate', name: 'api_contracts_generate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function generate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (empty($data['booking_id'])) {
            return $this->json(['error' => 'booking_id est requis'], 400);
        }

        $booking = $this->bookingRepository->find($data['booking_id']);
        if (!$booking) {
            return $this->json(['error' => 'Réservation introuvable'], 404);
        }

        $user = $this->getUser();
        if (!$this->canAccess($booking)) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        try {
            $contract = $this->contractService->generateContract($booking, $data['template_type'] ?? 'furnished_rental');
            return $this->json(['success' => true, 'data' => $this->serializeContract($contract)], 201);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /** GET /api/v1/contracts/booking/{id} */
    #[Route('/booking/{id}', name: 'api_contracts_get_by_booking', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function getByBooking(int $id): JsonResponse
    {
        $booking = $this->bookingRepository->find($id);
        if (!$booking || !$this->canAccess($booking)) {
            return $this->json(['error' => 'Introuvable ou accès refusé'], 404);
        }

        $contract = $this->contractRepository->findOneBy(['booking' => $booking]);
        if (!$contract) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }

        return $this->json(['success' => true, 'data' => $this->serializeContract($contract)]);
    }

    /** GET /api/v1/contracts/{id} */
    #[Route('/{id}', name: 'api_contracts_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function get(int $id): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        if (!$this->canAccess($contract->getBooking())) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        return $this->json(['success' => true, 'data' => $this->serializeContract($contract)]);
    }

    // ══════════════════════════════════════════════════════════════
    //  PDF
    // ══════════════════════════════════════════════════════════════

    /** POST /api/v1/contracts/{id}/upload-pdf */
    #[Route('/{id}/upload-pdf', name: 'api_contracts_upload_pdf', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadPdf(int $id, Request $request): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut téléverser le PDF'], 403);
        }

        $file = $request->files->get('pdf');
        if (!$file) {
            return $this->json(['error' => 'Fichier PDF requis'], 400);
        }

        $dir      = $this->getParameter('kernel.project_dir') . '/public/uploads/contracts';
        $filename = 'upload_' . $contract->getUniqueContractId() . '.pdf';
        $file->move($dir, $filename);

        $path = $dir . '/' . $filename;

        try {
            $this->contractService->uploadPdf($contract, $path, $user);
            return $this->json(['success' => true, 'data' => $this->serializeContract($contract)]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  SIGNATURES (locataire PREMIER, propriétaire SECOND)
    // ══════════════════════════════════════════════════════════════

    /** POST /api/v1/contracts/{id}/sign-tenant */
    #[Route('/{id}/sign-tenant', name: 'api_contracts_sign_tenant', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function signTenant(int $id, Request $request): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        if (!$booking->getTenant() || $booking->getTenant()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le locataire peut signer en premier'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['signature_url'])) {
            return $this->json(['error' => 'signature_url est requis'], 400);
        }

        try {
            $this->contractService->signByTenant($contract, $data['signature_url'], $user);
            return $this->json(['success' => true, 'message' => 'Contrat signé par le locataire', 'data' => $this->serializeContract($contract)]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    /** POST /api/v1/contracts/{id}/sign-owner */
    #[Route('/{id}/sign-owner', name: 'api_contracts_sign_owner', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function signOwner(int $id, Request $request): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut signer en second'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['signature_url'])) {
            return $this->json(['error' => 'signature_url est requis'], 400);
        }

        try {
            $this->contractService->signByOwner($contract, $data['signature_url'], $user);
            return $this->json(['success' => true, 'message' => 'Contrat verrouillé après double signature', 'data' => $this->serializeContract($contract)]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  PAIEMENT KKIAPAY
    // ══════════════════════════════════════════════════════════════

    /** POST /api/v1/contracts/{id}/set-payment */
    #[Route('/{id}/set-payment', name: 'api_contracts_set_payment', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function setPayment(int $id, Request $request): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut saisir les montants'], 403);
        }

        $data = json_decode($request->getContent(), true);
        foreach (['rent', 'deposit', 'months'] as $field) {
            if (!isset($data[$field])) {
                return $this->json(['error' => "$field est requis"], 400);
            }
        }

        try {
            $this->contractService->setPaymentAmounts(
                $contract,
                (float) $data['rent'],
                (float) $data['deposit'],
                (int)   $data['months'],
                $user
            );
            return $this->json(['success' => true, 'data' => $this->serializeContract($contract)]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    /** POST /api/v1/contracts/{id}/confirm-payment  — appelé côté client après Kkiapay success */
    #[Route('/{id}/confirm-payment', name: 'api_contracts_confirm_payment', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function confirmPayment(int $id, Request $request): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        // Seul le locataire peut confirmer son paiement
        if (!$booking->getTenant() || $booking->getTenant()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['transaction_id'])) {
            return $this->json(['error' => 'transaction_id est requis'], 400);
        }

        try {
            $this->contractService->confirmKkiapayPayment($contract, $data['transaction_id']);
            return $this->json([
                'success'       => true,
                'message'       => 'Paiement confirmé',
                'receipt_url'   => $contract->getReceiptUrl(),
                'quittance_url' => $contract->getQuittanceUrl(),
                'data'          => $this->serializeContract($contract),
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 402);
        }
    }

    /** GET /api/v1/contracts/kkiapay-config — clé publique Kkiapay pour widget frontend */
    #[Route('/kkiapay-config', name: 'api_contracts_kkiapay_config', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function kkiapayConfig(): JsonResponse
    {
        return $this->json([
            'public_key'   => $this->kkiapayService->getPublicKey(),
            'is_sandbox'   => $this->kkiapayService->isSandbox(),
            'callback_url' => $this->kkiapayService->getCallbackUrl(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  WEBHOOK KKIAPAY (public — pas d'auth JWT)
    // ══════════════════════════════════════════════════════════════

    /** POST /api/v1/contracts/webhook/kkiapay */
    #[Route('/webhook/kkiapay', name: 'api_contracts_webhook_kkiapay', methods: ['POST'])]
    public function webhookKkiapay(Request $request): JsonResponse
    {
        $rawBody   = $request->getContent();
        $signature = $request->headers->get('x-kkiapay-signature', '');
        $data      = json_decode($rawBody, true) ?? [];

        // Identifier le contrat via metadata ou référence dans le payload
        $contractRef = $data['metadata']['contract_uid'] ?? $data['reference'] ?? null;

        if (!$contractRef) {
            return $this->json(['error' => 'Référence contrat manquante dans le webhook'], 400);
        }

        $contract = $this->contractRepository->findOneBy(['uniqueContractId' => $contractRef]);
        if (!$contract) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }

        $success = $this->contractService->handleKkiapayWebhook($rawBody, $signature, $contract);

        return $this->json(['success' => $success], $success ? 200 : 400);
    }

    // ══════════════════════════════════════════════════════════════
    //  RESTITUTION
    // ══════════════════════════════════════════════════════════════

    /** POST /api/v1/contracts/{id}/request-restitution */
    #[Route('/{id}/request-restitution', name: 'api_contracts_request_restitution', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function requestRestitution(int $id): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        if (!$booking->getTenant() || $booking->getTenant()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le locataire peut demander la restitution'], 403);
        }

        try {
            $this->contractService->requestRestitution($contract, $user);
            return $this->json(['success' => true, 'message' => 'Demande de restitution soumise', 'data' => $this->serializeContract($contract)]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    /** POST /api/v1/contracts/{id}/process-restitution */
    #[Route('/{id}/process-restitution', name: 'api_contracts_process_restitution', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function processRestitution(int $id, Request $request): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        if ($booking->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Seul le propriétaire peut traiter la restitution'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data['decision'])) {
            return $this->json(['error' => 'decision est requis (full|partial|refused)'], 400);
        }

        try {
            $this->contractService->processRestitution(
                $contract,
                $user,
                $data['decision'],
                isset($data['retained_amount']) ? (float) $data['retained_amount'] : null,
                $data['notes'] ?? null
            );
            return $this->json(['success' => true, 'message' => 'Restitution traitée', 'data' => $this->serializeContract($contract)]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    /** POST /api/v1/contracts/{id}/complete-restitution */
    #[Route('/{id}/complete-restitution', name: 'api_contracts_complete_restitution', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function completeRestitution(int $id): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        $user    = $this->getUser();
        $booking = $contract->getBooking();

        // Admin ou propriétaire peut valider la restitution finale
        if ($booking->getOwner()->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        try {
            $this->contractService->completeRestitution($contract, $user);
            return $this->json(['success' => true, 'message' => 'Restitution validée et complétée', 'data' => $this->serializeContract($contract)]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  JOURNAL D'AUDIT
    // ══════════════════════════════════════════════════════════════

    /** GET /api/v1/contracts/{id}/audit-log */
    #[Route('/{id}/audit-log', name: 'api_contracts_audit_log', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function auditLog(int $id): JsonResponse
    {
        $contract = $this->findContractOr404($id);
        if ($contract instanceof JsonResponse) { return $contract; }

        if (!$this->canAccess($contract->getBooking())) {
            return $this->json(['error' => 'Accès refusé'], 403);
        }

        $logs = $this->auditLogRepository->findByContractId($id);

        $serialized = array_map(fn($log) => [
            'id'               => $log->getId(),
            'event_type'       => $log->getEventType(),
            'description'      => $log->getDescription(),
            'context'          => $log->getContext(),
            'document_hash'    => $log->getDocumentHash(),
            'ip_address'       => $log->getIpAddress(),
            'integrity_hash'   => $log->getLogIntegrityHash(),
            'user_id'          => $log->getUser()?->getId(),
            'user_email'       => $log->getUser()?->getEmail(),
            'created_at'       => $log->getCreatedAt()->format('Y-m-d H:i:s'),
        ], $logs);

        return $this->json(['success' => true, 'data' => $serialized]);
    }

    // ══════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════

    private function findContractOr404(int $id): Contract|JsonResponse
    {
        $contract = $this->contractRepository->find($id);
        if (!$contract) {
            return $this->json(['error' => 'Contrat introuvable'], 404);
        }
        return $contract;
    }

    private function canAccess($booking): bool
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }
        return $booking->getOwner()->getId() === $user->getId()
            || ($booking->getTenant() && $booking->getTenant()->getId() === $user->getId());
    }

    private function serializeContract(Contract $contract): array
    {
        $booking     = $contract->getBooking();
        $currentUser = $this->getUser();
        $userId      = $currentUser?->getId();
        return [
            'id'                         => $contract->getId(),
            'unique_contract_id'         => $contract->getUniqueContractId(),
            'booking_id'                 => $booking->getId(),
            'template_type'              => $contract->getTemplateType(),
            'status'                     => $contract->getStatus(),
            'pdf_url'                    => $contract->getPdfUrl(),
            // Rôle de l'utilisateur courant
            'is_owner'                   => $userId !== null && $booking->getOwner()->getId() === $userId,
            'is_tenant'                  => $userId !== null && $booking->getTenant() !== null && $booking->getTenant()->getId() === $userId,
            // Signatures
            'tenant_signed_at'           => $contract->getTenantSignedAt()?->format('Y-m-d H:i:s'),
            'owner_signed_at'            => $contract->getOwnerSignedAt()?->format('Y-m-d H:i:s'),
            'tenant_signature_meta'      => $contract->getTenantSignatureMeta(),
            'owner_signature_meta'       => $contract->getOwnerSignatureMeta(),
            'is_tenant_signed'           => $contract->isTenantSigned(),
            'is_owner_signed'            => $contract->isOwnerSigned(),
            'is_fully_signed'            => $contract->isFullySigned(),
            'is_locked'                  => $contract->isLocked(),
            'locked_at'                  => $contract->getLockedAt()?->format('Y-m-d H:i:s'),
            // Hashes
            'document_hash'              => $contract->getDocumentHash(),
            'signed_document_hash'       => $contract->getSignedDocumentHash(),
            // Paiement
            'rent_amount'                => $contract->getRentAmount(),
            'deposit_monthly_amount'     => $contract->getDepositMonthlyAmount(),
            'deposit_months'             => $contract->getDepositMonths(),
            'total_payment_amount'       => $contract->getTotalPaymentAmount(),
            'payment_status'             => $contract->getPaymentStatus(),
            'kkiapay_transaction_id'     => $contract->getKkiapayTransactionId(),
            'paid_at'                    => $contract->getPaidAt()?->format('Y-m-d H:i:s'),
            'receipt_url'                => $contract->getReceiptUrl(),
            'quittance_url'              => $contract->getQuittanceUrl(),
            // Restitution
            'restitution_status'         => $contract->getRestitutionStatus(),
            'restitution_retained_amount'=> $contract->getRestitutionRetainedAmount(),
            'restitution_notes'          => $contract->getRestitutionNotes(),
            'restitution_requested_at'   => $contract->getRestitutionRequestedAt()?->format('Y-m-d H:i:s'),
            'restitution_completed_at'   => $contract->getRestitutionCompletedAt()?->format('Y-m-d H:i:s'),
            'exit_report_url'            => $contract->getExitReportUrl(),
            // Données contrat
            'contract_data'              => $contract->getContractData(),
            'created_at'                 => $contract->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
