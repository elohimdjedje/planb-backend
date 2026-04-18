<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Contract;
use App\Entity\ContractAuditLog;
use App\Entity\User;
use App\Repository\ContractRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Service\EscrowService;

/**
 * Service de gestion complet des contrats de location.
 * Machine à états : draft → tenant_signed → owner_signed → locked
 * Sécurité : SHA-256, journal immuable, verrouillage, capture IP/UID.
 */
class ContractService
{
    private string $contractsDir;
    private string $appUrl;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContractRepository $contractRepository,
        private ParameterBagInterface $params,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private KKiaPayService $kkiapayService,
        private EscrowService $escrowService
    ) {
        $this->contractsDir = $params->get('kernel.project_dir') . '/public/uploads/contracts';
        // Utiliser ParameterBag pour éviter la dépendance directe à $_ENV (BUG-017)
        $this->appUrl = $params->has('app.url')
            ? $params->get('app.url')
            : ($_ENV['APP_URL'] ?? 'http://localhost:8000');

        if (!is_dir($this->contractsDir)) {
            mkdir($this->contractsDir, 0755, true);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  CRÉATION
    // ══════════════════════════════════════════════════════════════

    /**
     * Génère un contrat pour une réservation.
     * Status initial : draft
     */
    public function generateContract(Booking $booking, string $templateType = 'furnished_rental'): Contract
    {
        $existing = $this->contractRepository->findOneBy(['booking' => $booking]);
        if ($existing) {
            return $existing;
        }

        $listing = $booking->getListing();
        $owner   = $booking->getOwner();
        $tenant  = $booking->getTenant();

        $contractData = [
            'owner' => [
                'uid'     => $owner->getId(),
                'name'    => $owner->getFirstName() . ' ' . $owner->getLastName(),
                'email'   => $owner->getEmail(),
                'phone'   => $owner->getPhone(),
                'address' => $owner->getCity() . ', ' . $owner->getCountry(),
            ],
            'tenant' => [
                'uid'     => $tenant->getId(),
                'name'    => $tenant->getFirstName() . ' ' . $tenant->getLastName(),
                'email'   => $tenant->getEmail(),
                'phone'   => $tenant->getPhone(),
                'address' => $tenant->getCity() . ', ' . $tenant->getCountry(),
            ],
            'property' => [
                'title'   => $listing->getTitle(),
                'address' => $listing->getAddress() ?? $listing->getCity(),
                'city'    => $listing->getCity(),
                'country' => $listing->getCountry(),
            ],
            'rental' => [
                'start_date'   => $booking->getStartDate()->format('Y-m-d'),
                'end_date'     => $booking->getEndDate()->format('Y-m-d'),
                'monthly_rent' => $booking->getMonthlyRent(),
                'deposit'      => $booking->getDepositAmount(),
                'charges'      => $booking->getCharges(),
            ],
            'terms'        => $this->getDefaultTerms($templateType),
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];

        $contract = new Contract();
        $contract->setBooking($booking);
        $contract->setTemplateType($templateType);
        $contract->setContractData($contractData);
        $contract->setUniqueContractId($this->generateUniqueId());
        $contract->setStatus(Contract::STATUS_DRAFT);

        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        // Hash initial du document
        $hash = $this->computeDocumentHash($contract);
        $contract->setDocumentHash($hash);

        // Générer le PDF initial
        $pdfUrl = $this->generatePdf($contract);
        if ($pdfUrl) {
            $contract->setPdfUrl($pdfUrl);
        }

        $this->entityManager->flush();

        $this->audit($contract, null, ContractAuditLog::EVENT_CREATED, 'Contrat créé', [
            'template_type'      => $templateType,
            'unique_contract_id' => $contract->getUniqueContractId(),
            'document_hash'      => $hash,
        ]);

        return $contract;
    }

    /**
     * Téléverse un PDF de bail (fourni par le propriétaire).
     */
    public function uploadPdf(Contract $contract, string $filePath, User $user): void
    {
        if ($contract->isLocked()) {
            throw new \LogicException('Le contrat est verrouillé, impossible de modifier le document.');
        }

        $contract->setUploadedPdfPath($filePath);
        $contract->setTemplateType('uploaded');

        // Recompute hash with the uploaded file
        $hash = hash_file('sha256', $filePath) ?: $this->computeDocumentHash($contract);
        $contract->setDocumentHash($hash);
        $contract->setStatus(Contract::STATUS_DRAFT);

        $this->entityManager->flush();

        $this->audit($contract, $user, ContractAuditLog::EVENT_PDF_UPLOADED, 'PDF téléversé par le propriétaire', [
            'document_hash' => $hash,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  SIGNATURES (tenant d'abord, puis owner)
    // ══════════════════════════════════════════════════════════════

    /**
     * Signature du locataire (ÉTAPE 1 obligatoire).
     */
    public function signByTenant(Contract $contract, string $signatureUrl, User $tenant): void
    {
        if ($contract->isLocked()) {
            throw new \LogicException('Contrat verrouillé — aucune signature possible.');
        }
        if ($contract->getStatus() !== Contract::STATUS_DRAFT) {
            throw new \LogicException('Seul un contrat en brouillon peut être signé par le locataire.');
        }
        if ($contract->isTenantSigned()) {
            throw new \LogicException('Le locataire a déjà signé.');
        }

        $ip        = $this->getClientIp();
        $userAgent = $this->requestStack->getCurrentRequest()?->headers->get('User-Agent') ?? '';

        $contract->setTenantSignedAt(new \DateTime());
        $contract->setTenantSignatureUrl($signatureUrl);
        $contract->setTenantSignatureMeta([
            'uid'        => $tenant->getId(),
            'email'      => $tenant->getEmail(),
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'timestamp'  => (new \DateTime())->format('Y-m-d H:i:s'),
            'doc_hash'   => $contract->getDocumentHash(),
        ]);
        $contract->setStatus(Contract::STATUS_TENANT_SIGNED);

        $this->entityManager->flush();

        $this->audit($contract, $tenant, ContractAuditLog::EVENT_TENANT_SIGNED, 'Contrat signé par le locataire', [
            'ip'         => $ip,
            'doc_hash'   => $contract->getDocumentHash(),
        ], $ip);

        $this->logger->info('Contrat signé (locataire)', ['contract_id' => $contract->getId(), 'tenant_id' => $tenant->getId()]);
    }

    /**
     * Signature du propriétaire (ÉTAPE 2 — après locataire).
     * Déclenche le verrouillage automatique.
     */
    public function signByOwner(Contract $contract, string $signatureUrl, User $owner): void
    {
        if ($contract->isLocked()) {
            throw new \LogicException('Contrat déjà verrouillé.');
        }
        if ($contract->getStatus() !== Contract::STATUS_TENANT_SIGNED) {
            throw new \LogicException('Le locataire doit signer en premier.');
        }

        $ip        = $this->getClientIp();
        $userAgent = $this->requestStack->getCurrentRequest()?->headers->get('User-Agent') ?? '';

        $contract->setOwnerSignedAt(new \DateTime());
        $contract->setOwnerSignatureUrl($signatureUrl);
        $contract->setOwnerSignatureMeta([
            'uid'        => $owner->getId(),
            'email'      => $owner->getEmail(),
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'timestamp'  => (new \DateTime())->format('Y-m-d H:i:s'),
            'doc_hash'   => $contract->getDocumentHash(),
        ]);
        $contract->setStatus(Contract::STATUS_OWNER_SIGNED);

        $this->entityManager->flush();

        $this->audit($contract, $owner, ContractAuditLog::EVENT_OWNER_SIGNED, 'Contrat signé par le propriétaire', [
            'ip'       => $ip,
            'doc_hash' => $contract->getDocumentHash(),
        ], $ip);

        // Verouillage automatique après double signature
        $this->lockContract($contract, $owner);
    }

    /**
     * Verrouille le contrat (immutable après cette étape).
     * Génère la version PDF finale signée.
     */
    private function lockContract(Contract $contract, User $triggeredBy): void
    {
        $contract->setLockedAt(new \DateTime());
        $contract->setStatus(Contract::STATUS_LOCKED);

        // Générer PDF final avec signatures
        $pdfUrl = $this->generateSignedPdf($contract);
        if ($pdfUrl) {
            $contract->setPdfUrl($pdfUrl);
        }

        // Hash du document final signé
        $finalHash = $this->computeDocumentHash($contract);
        $contract->setSignedDocumentHash($finalHash);

        $this->entityManager->flush();

        $this->audit($contract, $triggeredBy, ContractAuditLog::EVENT_LOCKED, 'Contrat verrouillé — version finale générée', [
            'signed_doc_hash' => $finalHash,
            'pdf_url'         => $pdfUrl,
        ]);

        $this->logger->info('Contrat verrouillé', ['contract_id' => $contract->getId()]);
    }

    // ══════════════════════════════════════════════════════════════
    //  PAIEMENT KKIAPAY
    // ══════════════════════════════════════════════════════════════

    /**
     * Définit les montants de paiement (loyer + caution) côté propriétaire.
     * Calcul : total = loyer + (caution × mois)
     */
    public function setPaymentAmounts(Contract $contract, float $rent, float $deposit, int $months, User $owner): void
    {
        if (!$contract->isLocked()) {
            throw new \LogicException('Le contrat doit être verrouillé avant de saisir les montants.');
        }
        if ($contract->getPaymentStatus() !== null) {
            throw new \LogicException('Un paiement est déjà en cours ou effectué.');
        }

        $total = $rent + ($deposit * $months);

        $contract->setRentAmount((string) $rent);
        $contract->setDepositMonthlyAmount((string) $deposit);
        $contract->setDepositMonths($months);
        $contract->setTotalPaymentAmount((string) $total);
        $contract->setPaymentStatus('payment_pending');

        $this->entityManager->flush();

        $this->audit($contract, $owner, ContractAuditLog::EVENT_PAYMENT_INITIATED, 'Montants de paiement saisis', [
            'rent'    => $rent,
            'deposit' => $deposit,
            'months'  => $months,
            'total'   => $total,
        ]);
    }

    /**
     * Confirme un paiement réussi via Kkiapay (après vérification webhook/backend).
     */
    public function confirmKkiapayPayment(Contract $contract, string $transactionId): void
    {
        // Protection double paiement
        if ($contract->getPaymentStatus() === 'payment_success') {
            throw new \LogicException('Ce contrat est déjà payé (transaction: ' . $contract->getKkiapayTransactionId() . ')');
        }

        // Vérifier la transaction côté Kkiapay
        $result = $this->kkiapayService->verifyTransaction($transactionId);
        if (!$result['success']) {
            $contract->setPaymentStatus('payment_failed');
            $this->entityManager->flush();
            $this->audit($contract, null, ContractAuditLog::EVENT_PAYMENT_FAILED, 'Paiement échoué : ' . ($result['error'] ?? ''), [
                'transaction_id' => $transactionId,
            ]);
            throw new \RuntimeException('Paiement non confirmé : ' . ($result['error'] ?? 'Erreur inconnue'));
        }

        $contract->setKkiapayTransactionId($transactionId);
        $contract->setPaymentStatus('payment_success');
        $contract->setPaidAt(new \DateTime());

        // Activer la réservation et créer le compte séquestre après paiement confirmé
        $booking = $contract->getBooking();
        if ($booking->getStatus() === 'confirmed') {
            $booking->setStatus('active');
            $booking->setCheckInDate($booking->getStartDate());
        }

        // Synchroniser les montants du booking depuis le contrat si nécessaire
        if ($contract->getRentAmount() !== null) {
            $booking->setMonthlyRent($contract->getRentAmount());
        }
        if ($contract->getDepositMonthlyAmount() !== null) {
            $booking->setDepositAmount($contract->getDepositMonthlyAmount());
        }

        // Marquer la caution et le 1er loyer comme payés, puis créer l'escrow
        $booking->setDepositPaid(true);
        $booking->setFirstRentPaid(true);
        $this->escrowService->createEscrowAccount($booking);

        // Générer quittance et reçu
        $receiptUrl   = $this->generateReceipt($contract);
        $quittanceUrl = $this->generateQuittance($contract);
        if ($receiptUrl)   { $contract->setReceiptUrl($receiptUrl); }
        if ($quittanceUrl) { $contract->setQuittanceUrl($quittanceUrl); }

        $this->entityManager->flush();

        $this->audit($contract, null, ContractAuditLog::EVENT_PAYMENT_SUCCESS, 'Paiement confirmé via Kkiapay', [
            'transaction_id' => $transactionId,
            'amount'         => $contract->getTotalPaymentAmount(),
        ]);
    }

    /**
     * Traite le webhook Kkiapay entrant (vérification signature HMAC).
     */
    public function handleKkiapayWebhook(string $rawBody, string $signature, Contract $contract): bool
    {
        if (!$this->kkiapayService->verifyWebhookSignature($rawBody, $signature)) {
            $this->logger->warning('Webhook Kkiapay : signature invalide', ['contract_id' => $contract->getId()]);
            return false;
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return false;
        }

        $webhookData = $this->kkiapayService->processWebhook($data);

        if ($webhookData['isSuccess'] && $webhookData['transactionId']) {
            try {
                $this->confirmKkiapayPayment($contract, $webhookData['transactionId']);
                return true;
            } catch (\Exception $e) {
                $this->logger->error('Webhook Kkiapay : erreur confirmation', ['error' => $e->getMessage()]);
                return false;
            }
        }

        // Paiement échoué
        $contract->setPaymentStatus('payment_failed');
        $this->entityManager->flush();
        $this->audit($contract, null, ContractAuditLog::EVENT_PAYMENT_FAILED, 'Webhook Kkiapay : paiement échoué', $webhookData);
        return false;
    }

    // ══════════════════════════════════════════════════════════════
    //  RESTITUTION
    // ══════════════════════════════════════════════════════════════

    /**
     * Locataire demande la restitution de caution.
     */
    public function requestRestitution(Contract $contract, User $tenant): void
    {
        if ($contract->getPaymentStatus() !== 'payment_success') {
            throw new \LogicException('Le paiement doit être confirmé pour demander une restitution.');
        }
        if ($contract->getRestitutionStatus() !== null) {
            throw new \LogicException('Une demande de restitution existe déjà.');
        }

        $contract->setRestitutionStatus('restitution_requested');
        $contract->setRestitutionRequestedAt(new \DateTime());
        $this->entityManager->flush();

        $this->audit($contract, $tenant, ContractAuditLog::EVENT_RESTITUTION_REQUESTED, 'Demande de restitution soumise', [], $this->getClientIp());
    }

    /**
     * Propriétaire traite la restitution.
     * @param string $decision 'full' | 'partial' | 'refused'
     */
    public function processRestitution(
        Contract $contract,
        User $owner,
        string $decision,
        ?float $retainedAmount = null,
        ?string $notes = null
    ): void {
        if ($contract->getRestitutionStatus() !== 'restitution_requested') {
            throw new \LogicException('Pas de demande de restitution en attente.');
        }
        if (!in_array($decision, ['full', 'partial', 'refused'], true)) {
            throw new \InvalidArgumentException('Décision invalide (full|partial|refused).');
        }

        // restitution_requested → restitution_processing (owner is reviewing)
        $contract->setRestitutionStatus('restitution_processing');
        $contract->setRestitutionNotes($notes);

        if ($decision === 'partial' && $retainedAmount) {
            $contract->setRestitutionRetainedAmount((string) $retainedAmount);
        }

        $this->entityManager->flush();

        $this->audit($contract, $owner, ContractAuditLog::EVENT_RESTITUTION_PROCESSED, 'Restitution en cours de traitement par le propriétaire — décision : ' . $decision, [
            'decision'        => $decision,
            'retained_amount' => $retainedAmount,
            'notes'           => $notes,
        ]);

        // Générer le procès-verbal de sortie puis passer à restitution_validated
        $exitReportUrl = $this->generateExitReport($contract, $decision, $retainedAmount, $notes);
        if ($exitReportUrl) {
            $contract->setExitReportUrl($exitReportUrl);
        }

        $contract->setRestitutionStatus('restitution_validated');
        $this->entityManager->flush();

        $this->audit($contract, $owner, ContractAuditLog::EVENT_RESTITUTION_VALIDATED, 'Restitution validée — procès-verbal généré', [
            'decision'        => $decision,
            'exit_report_url' => $exitReportUrl,
        ]);
    }

    /**
     * Clôture finale de la restitution (après validation et accord des deux parties).
     * restitution_validated → restitution_completed
     */
    public function completeRestitution(Contract $contract, User $actor): void
    {
        if (!in_array($contract->getRestitutionStatus(), ['restitution_validated', 'restitution_processing'], true)) {
            throw new \LogicException('La restitution doit être validée avant d\'être complétée.');
        }

        $contract->setRestitutionStatus('restitution_completed');
        $contract->setRestitutionCompletedAt(new \DateTime());
        $this->entityManager->flush();

        $this->audit($contract, $actor, ContractAuditLog::EVENT_RESTITUTION_COMPLETED, 'Restitution validée et complétée', []);
    }

    // ══════════════════════════════════════════════════════════════
    //  PDF / DOCUMENTS
    // ══════════════════════════════════════════════════════════════

    private function generatePdf(Contract $contract): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return '';
        }

        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($this->buildContractHtml($contract, false));
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $filename = 'contract_' . $contract->getUniqueContractId() . '.pdf';
            file_put_contents($this->contractsDir . '/' . $filename, $dompdf->output());

            return $this->appUrl . '/uploads/contracts/' . $filename;
        } catch (\Exception $e) {
            $this->logger->error('Erreur PDF contrat', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function generateSignedPdf(Contract $contract): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return '';
        }

        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($this->buildContractHtml($contract, true));
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $filename = 'contract_signed_' . $contract->getUniqueContractId() . '.pdf';
            file_put_contents($this->contractsDir . '/' . $filename, $dompdf->output());

            return $this->appUrl . '/uploads/contracts/' . $filename;
        } catch (\Exception $e) {
            $this->logger->error('Erreur PDF signé', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function generateReceipt(Contract $contract): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return '';
        }
        try {
            $data = $contract->getContractData();
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Reçu</title><style>body{font-family:Arial,sans-serif;margin:40px}h1{color:#e67e22}</style></head><body>'
                . '<h1>REÇU DE PAIEMENT</h1>'
                . '<p><strong>Contrat :</strong> ' . $contract->getUniqueContractId() . '</p>'
                . '<p><strong>Transaction Kkiapay :</strong> ' . $contract->getKkiapayTransactionId() . '</p>'
                . '<p><strong>Montant payé :</strong> ' . $contract->getTotalPaymentAmount() . ' XOF</p>'
                . '<p><strong>Date :</strong> ' . $contract->getPaidAt()?->format('d/m/Y H:i') . '</p>'
                . '<p><strong>Locataire :</strong> ' . ($data['tenant']['name'] ?? '') . '</p>'
                . '<p><strong>Bailleur :</strong> ' . ($data['owner']['name'] ?? '') . '</p>'
                . '<p>Généré par PlanB — ' . date('d/m/Y H:i') . '</p>'
                . '</body></html>';

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();

            $filename = 'receipt_' . $contract->getUniqueContractId() . '.pdf';
            file_put_contents($this->contractsDir . '/' . $filename, $dompdf->output());

            return $this->appUrl . '/uploads/contracts/' . $filename;
        } catch (\Exception $e) {
            return '';
        }
    }

    private function generateQuittance(Contract $contract): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return '';
        }
        try {
            $data = $contract->getContractData();
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Quittance</title><style>body{font-family:Arial,sans-serif;margin:40px}h1{color:#e67e22}</style></head><body>'
                . '<h1>QUITTANCE DE LOYER</h1>'
                . '<p><strong>Contrat :</strong> ' . $contract->getUniqueContractId() . '</p>'
                . '<p><strong>Bailleur :</strong> ' . ($data['owner']['name'] ?? '') . '</p>'
                . '<p><strong>Locataire :</strong> ' . ($data['tenant']['name'] ?? '') . '</p>'
                . '<p><strong>Bien :</strong> ' . ($data['property']['title'] ?? '') . '</p>'
                . '<p><strong>Loyer :</strong> ' . $contract->getRentAmount() . ' XOF</p>'
                . '<p><strong>Caution :</strong> ' . $contract->getDepositMonthlyAmount() . ' XOF × ' . $contract->getDepositMonths() . ' mois</p>'
                . '<p><strong>Total payé :</strong> ' . $contract->getTotalPaymentAmount() . ' XOF</p>'
                . '<p><strong>Date :</strong> ' . $contract->getPaidAt()?->format('d/m/Y') . '</p>'
                . '<p>Généré par PlanB — ' . date('d/m/Y H:i') . '</p>'
                . '</body></html>';

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();

            $filename = 'quittance_' . $contract->getUniqueContractId() . '.pdf';
            file_put_contents($this->contractsDir . '/' . $filename, $dompdf->output());

            return $this->appUrl . '/uploads/contracts/' . $filename;
        } catch (\Exception $e) {
            return '';
        }
    }

    private function generateExitReport(Contract $contract, string $decision, ?float $retained, ?string $notes): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return '';
        }
        try {
            $data = $contract->getContractData();
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PV Sortie</title><style>body{font-family:Arial,sans-serif;margin:40px}h1{color:#e67e22}</style></head><body>'
                . '<h1>PROCÈS-VERBAL DE SORTIE</h1>'
                . '<p><strong>Contrat :</strong> ' . $contract->getUniqueContractId() . '</p>'
                . '<p><strong>Bailleur :</strong> ' . ($data['owner']['name'] ?? '') . '</p>'
                . '<p><strong>Locataire :</strong> ' . ($data['tenant']['name'] ?? '') . '</p>'
                . '<p><strong>Décision :</strong> ' . $decision . '</p>'
                . ($retained ? '<p><strong>Retenue :</strong> ' . $retained . ' XOF</p>' : '')
                . ($notes ? '<p><strong>Notes :</strong> ' . htmlspecialchars($notes) . '</p>' : '')
                . '<p><strong>Date :</strong> ' . date('d/m/Y H:i') . '</p>'
                . '<p><em>Signature bailleur : ' . ($contract->getOwnerSignatureMeta()['timestamp'] ?? '-') . '</em></p>'
                . '<p><em>Signature locataire : ' . ($contract->getTenantSignatureMeta()['timestamp'] ?? '-') . '</em></p>'
                . '</body></html>';

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();

            $filename = 'exit_' . $contract->getUniqueContractId() . '.pdf';
            file_put_contents($this->contractsDir . '/' . $filename, $dompdf->output());

            return $this->appUrl . '/uploads/contracts/' . $filename;
        } catch (\Exception $e) {
            return '';
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  HTML DU CONTRAT
    // ══════════════════════════════════════════════════════════════

    private function buildContractHtml(Contract $contract, bool $withSignatures): string
    {
        $data    = $contract->getContractData();
        $booking = $contract->getBooking();

        $sigBlock = '';
        if ($withSignatures) {
            $tMeta = $contract->getTenantSignatureMeta() ?? [];
            $oMeta = $contract->getOwnerSignatureMeta() ?? [];
            $sigBlock = '
            <div class="section">
                <h2>SIGNATURES NUMÉRIQUES</h2>
                <table width="100%"><tr>
                    <td width="50%" style="padding:10px;border-top:2px solid #333;text-align:center">
                        <strong>Le Locataire</strong><br>' . htmlspecialchars($data['tenant']['name'] ?? '') . '<br>
                        Signé le : ' . ($tMeta['timestamp'] ?? '-') . '<br>
                        IP : ' . ($tMeta['ip'] ?? '-') . '<br>
                        Hash : ' . substr($contract->getDocumentHash() ?? '-', 0, 16) . '…
                    </td>
                    <td width="50%" style="padding:10px;border-top:2px solid #333;text-align:center">
                        <strong>Le Bailleur</strong><br>' . htmlspecialchars($data['owner']['name'] ?? '') . '<br>
                        Signé le : ' . ($oMeta['timestamp'] ?? '-') . '<br>
                        IP : ' . ($oMeta['ip'] ?? '-') . '<br>
                        Hash : ' . substr($contract->getDocumentHash() ?? '-', 0, 16) . '…
                    </td>
                </tr></table>
                <p style="font-size:11px;color:#666;text-align:center;margin-top:10px">
                    Hash document signé : <code>' . ($contract->getSignedDocumentHash() ?? '-') . '</code>
                </p>
            </div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Contrat {$contract->getUniqueContractId()}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        h1   { color: #e67e22; text-align: center; }
        h2   { color: #333; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
        .section { margin: 20px 0; }
        .badge { background:#e67e22;color:#fff;padding:4px 12px;border-radius:20px;font-size:12px; }
    </style>
</head>
<body>
    <h1>CONTRAT DE LOCATION</h1>
    <p style="text-align:center">
        <span class="badge">N° {$contract->getUniqueContractId()}</span>
        &nbsp;|&nbsp; Généré le {$data['generated_at']}
    </p>

    <div class="section">
        <h2>1. PARTIES</h2>
        <p><strong>Bailleur :</strong> {$data['owner']['name']} — {$data['owner']['email']} — {$data['owner']['phone']}</p>
        <p><strong>Locataire :</strong> {$data['tenant']['name']} — {$data['tenant']['email']} — {$data['tenant']['phone']}</p>
    </div>

    <div class="section">
        <h2>2. BIEN LOUÉ</h2>
        <p><strong>{$data['property']['title']}</strong><br>{$data['property']['address']}, {$data['property']['city']}, {$data['property']['country']}</p>
    </div>

    <div class="section">
        <h2>3. CONDITIONS</h2>
        <p>Période : {$data['rental']['start_date']} → {$data['rental']['end_date']}</p>
        <p>Loyer mensuel : {$data['rental']['monthly_rent']} XOF&nbsp; | &nbsp;Caution : {$data['rental']['deposit']} XOF&nbsp; | &nbsp;Charges : {$data['rental']['charges']} XOF</p>
    </div>

    <div class="section">
        <h2>4. CLAUSES</h2>
        {$this->formatTerms($data['terms'])}
    </div>

    {$sigBlock}

    <p style="font-size:11px;color:#999;text-align:center;margin-top:30px">
        Document généré par Plan B — Hash intégrité : {$contract->getDocumentHash()}
    </p>
</body>
</html>
HTML;
    }

    // ══════════════════════════════════════════════════════════════
    //  SÉCURITÉ / HASH / AUDIT
    // ══════════════════════════════════════════════════════════════

    /**
     * Calcule le hash SHA-256 du contrat (données + dates + id unique).
     */
    public function computeDocumentHash(Contract $contract): string
    {
        $payload = json_encode([
            'id'           => $contract->getId(),
            'uid'          => $contract->getUniqueContractId(),
            'data'         => $contract->getContractData(),
            'template'     => $contract->getTemplateType(),
            'generated_at' => $contract->getCreatedAt()?->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }

    /**
     * Crée une entrée de journal d'événements immuable.
     */
    public function audit(
        Contract $contract,
        ?User $user,
        string $eventType,
        string $description,
        array $context = [],
        ?string $ip = null
    ): void {
        $log = new ContractAuditLog();
        $log->setContract($contract);
        $log->setUser($user);
        $log->setEventType($eventType);
        $log->setDescription($description);
        $log->setContext($context);
        $log->setDocumentHash($contract->getDocumentHash());
        $log->setIpAddress($ip ?? $this->getClientIp());
        $log->setUserAgent($this->requestStack->getCurrentRequest()?->headers->get('User-Agent') ?? '');
        $log->computeIntegrityHash();

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function getClientIp(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }
        return $request->headers->get('X-Forwarded-For')
            ?? $request->headers->get('X-Real-IP')
            ?? $request->getClientIp();
    }

    private function generateUniqueId(): string
    {
        return 'PLANB-' . date('Y') . '-' . strtoupper(substr(uniqid('', true), -6));
    }

    // ══════════════════════════════════════════════════════════════
    //  HELPERS CONTRAT HTML
    // ══════════════════════════════════════════════════════════════

    private function formatTerms(array $terms): string
    {
        $html = '';
        foreach ($terms as $term) {
            $html .= '<p><strong>' . htmlspecialchars($term['title']) . '</strong><br>' . htmlspecialchars($term['content']) . '</p>';
        }
        return $html;
    }

    private function getDefaultTerms(string $templateType): array
    {
        $base = [
            ['title' => 'Article 1 - Objet',  'content' => 'Le présent contrat a pour objet la location du bien décrit ci-dessus.'],
            ['title' => 'Article 2 - Durée',   'content' => 'La location est conclue pour la durée indiquée.'],
            ['title' => 'Article 3 - Loyer',   'content' => 'Le loyer est payable mensuellement via la plateforme PlanB.'],
            ['title' => 'Article 4 - Caution', 'content' => 'La caution est séquestrée par PlanB et restituée en fin de bail selon l\'état du bien.'],
        ];

        if ($templateType === 'furnished_rental') {
            $base[] = ['title' => 'Article 5 - Mobilier', 'content' => 'Le bien est loué meublé. L\'inventaire est joint au contrat.'];
        }

        return $base;
    }
}

