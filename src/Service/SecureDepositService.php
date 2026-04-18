<?php

namespace App\Service;

use App\Entity\DepositDispute;
use App\Entity\Listing;
use App\Entity\SecureDeposit;
use App\Entity\User;
use App\Repository\DepositDisputeRepository;
use App\Repository\SecureDepositRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrateur du système de caution sécurisée (escrow).
 *
 * Workflow complet :
 *  1. Locataire crée le contrat et signe  → draft → signed_tenant
 *  2. Propriétaire prend connaissance et signe → signed_landlord
 *  3. Admin signe → pending_payment (demande de paiement envoyée au locataire)
 *  4. Locataire paie → active
 *  5. Locataire demande résiliation → termination_requested
 *  6. Admin traite → admin_review → envoie au propriétaire
 *  7. Propriétaire inspecte → landlord_inspection → valide et signe → landlord_validated
 *  8. Locataire signe restitution → tenant_exit_validated
 *  9. Admin signe final et valide → refund_processing → completed
 *
 * ⚠️  La plateforme ne stocke JAMAIS l'argent.
 *     Les fonds sont détenus par un prestataire de paiement agréé.
 *     La plateforme prélève 5 % de commission via le prestataire.
 */
class SecureDepositService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecureDepositRepository $depositRepo,
        private DepositDisputeRepository $disputeRepo,
        private LoggerInterface $logger,
    ) {}

    // ═════════════════════════════════════════════════════════
    // 1. CRÉATION DU CONTRAT — Le locataire crée et signe
    // ═════════════════════════════════════════════════════════

    /**
     * Crée le contrat de caution (status = signed_tenant).
     * Le locataire signe automatiquement à la création.
     */
    public function createContract(
        Listing $listing,
        User    $tenant,
        array   $data
    ): SecureDeposit {
        $landlord = $listing->getUser();

        if ($tenant->getId() === $landlord->getId()) {
            throw new \InvalidArgumentException('Le locataire et le bailleur ne peuvent pas être la même personne');
        }

        if (!$listing->isSecureDepositEnabled()) {
            throw new \InvalidArgumentException('La caution sécurisée n\'est pas activée pour cette annonce');
        }

        $deposit = new SecureDeposit();
        $deposit->setListing($listing);
        $deposit->setTenant($tenant);
        $deposit->setLandlord($landlord);
        $deposit->setDepositAmount($data['deposit_amount'] ?? $listing->getDepositAmountRequired());
        $deposit->setPropertyType($data['property_type'] ?? 'maison');
        $deposit->setPropertyDescription($data['property_description'] ?? $listing->getTitle());
        $deposit->setPropertyAddress($data['property_address'] ?? $listing->getAddress() ?? $listing->getCity());

        // Pièces d'identité
        if (isset($data['tenant_id_type']))   $deposit->setTenantIdType($data['tenant_id_type']);
        if (isset($data['tenant_id_number'])) $deposit->setTenantIdNumber($data['tenant_id_number']);
        if (isset($data['landlord_id_type']))   $deposit->setLandlordIdType($data['landlord_id_type']);
        if (isset($data['landlord_id_number'])) $deposit->setLandlordIdNumber($data['landlord_id_number']);

        // Dates de location
        if (isset($data['rental_start_date'])) {
            $deposit->setRentalStartDate(new \DateTime($data['rental_start_date']));
        }
        if (isset($data['rental_end_date'])) {
            $deposit->setRentalEndDate(new \DateTime($data['rental_end_date']));
        }

        // Le locataire signe automatiquement à la création
        $deposit->setTenantSignedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_SIGNED_TENANT);

        $this->entityManager->persist($deposit);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Contrat créé et signé par le locataire', [
            'id' => $deposit->getId(),
            'listing' => $listing->getId(),
            'amount' => $deposit->getDepositAmount(),
        ]);

        return $deposit;
    }

    // ═════════════════════════════════════════════════════════
    // 2. SIGNATURE DU PROPRIÉTAIRE
    // ═════════════════════════════════════════════════════════

    /**
     * Le propriétaire prend connaissance du contrat et signe.
     * Status : signed_tenant → signed_landlord
     */
    public function signByLandlord(SecureDeposit $deposit): void
    {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_SIGNED_TENANT) {
            throw new \InvalidArgumentException('Le contrat doit être signé par le locataire d\'abord');
        }

        $deposit->setLandlordSignedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_SIGNED_LANDLORD);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Contrat signé par le propriétaire', [
            'id' => $deposit->getId(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 3. SIGNATURE DE L'ADMIN → DEMANDE DE PAIEMENT
    // ═════════════════════════════════════════════════════════

    /**
     * L'admin valide et signe le contrat.
     * Status : signed_landlord → pending_payment
     * Une demande de paiement est envoyée au locataire.
     */
    public function signByAdmin(SecureDeposit $deposit): void
    {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_SIGNED_LANDLORD) {
            throw new \InvalidArgumentException('Le contrat doit être signé par le propriétaire d\'abord');
        }

        $deposit->setAdminSignedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_PENDING_PAYMENT);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Contrat signé par l\'admin — demande de paiement envoyée', [
            'id' => $deposit->getId(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 4. CONFIRMATION DU PAIEMENT
    // ═════════════════════════════════════════════════════════

    /**
     * Confirme que le paiement a été reçu (après les 3 signatures).
     * Status : pending_payment → active
     */
    public function confirmPayment(
        SecureDeposit $deposit,
        string $transactionId,
        string $provider,
        string $method
    ): void {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_PENDING_PAYMENT) {
            throw new \InvalidArgumentException('Ce dépôt n\'est pas en attente de paiement');
        }

        if (!$deposit->isFullySigned()) {
            throw new \InvalidArgumentException('Le contrat doit être signé par toutes les parties avant le paiement');
        }

        $deposit->setTransactionId($transactionId);
        $deposit->setPaymentProvider($provider);
        $deposit->setPaymentMethod($method);
        $deposit->setPaidAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_ACTIVE);

        $this->entityManager->flush();

        $this->logger->info('[Escrow] Paiement confirmé — caution active', [
            'id' => $deposit->getId(),
            'transaction' => $transactionId,
            'provider' => $provider,
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 5. DEMANDE DE RÉSILIATION (Locataire)
    // ═════════════════════════════════════════════════════════

    /**
     * Le locataire demande la résiliation et la restitution de sa caution.
     * Status : active → termination_requested
     */
    public function requestTermination(SecureDeposit $deposit): void
    {
        if (!$deposit->canRequestTermination()) {
            throw new \InvalidArgumentException('Impossible de demander la résiliation dans cet état');
        }

        $deposit->setTerminationRequestedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_TERMINATION_REQ);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Demande de résiliation', ['id' => $deposit->getId()]);
    }

    // ═════════════════════════════════════════════════════════
    // 6. REVUE PAR L'ADMIN
    // ═════════════════════════════════════════════════════════

    /**
     * L'admin traite la demande de résiliation et l'envoie au propriétaire.
     * Status : termination_requested → admin_review → landlord_inspection
     */
    public function adminReviewTermination(SecureDeposit $deposit): void
    {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_TERMINATION_REQ) {
            throw new \InvalidArgumentException('Pas de demande de résiliation à traiter');
        }

        $deposit->setAdminReviewAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_LANDLORD_INSPECTION);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Admin a traité la demande → envoyée au propriétaire', [
            'id' => $deposit->getId(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 7. INSPECTION ET SIGNATURE DU PROPRIÉTAIRE (sortie)
    // ═════════════════════════════════════════════════════════

    /**
     * Le propriétaire inspecte le bien, ajoute ses notes et signe.
     * Status : landlord_inspection → landlord_validated
     */
    public function landlordInspectAndSign(SecureDeposit $deposit, ?string $notes = null): void
    {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_LANDLORD_INSPECTION) {
            throw new \InvalidArgumentException('L\'inspection n\'est pas encore autorisée');
        }

        $deposit->setLandlordInspectionAt(new \DateTime());
        $deposit->setLandlordInspectionNotes($notes);
        $deposit->setLandlordExitSignedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_LANDLORD_VALIDATED);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Propriétaire a inspecté et signé', [
            'id' => $deposit->getId(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 8. SIGNATURE DU LOCATAIRE (sortie)
    // ═════════════════════════════════════════════════════════

    /**
     * Le locataire signe le document de restitution.
     * Status : landlord_validated → tenant_exit_validated
     */
    public function tenantExitSign(SecureDeposit $deposit): void
    {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_LANDLORD_VALIDATED) {
            throw new \InvalidArgumentException('Le propriétaire doit valider d\'abord');
        }

        $deposit->setTenantExitSignedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_TENANT_EXIT_VALID);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Locataire a signé la restitution', [
            'id' => $deposit->getId(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 9. SIGNATURE FINALE ADMIN → REMBOURSEMENT
    // ═════════════════════════════════════════════════════════

    /**
     * L'admin signe en dernier, valide et déclenche le remboursement.
     * Status : tenant_exit_validated → refund_processing
     */
    public function adminFinalSign(SecureDeposit $deposit): void
    {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_TENANT_EXIT_VALID) {
            throw new \InvalidArgumentException('Le locataire doit signer d\'abord');
        }

        $deposit->setAdminFinalSignedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_REFUND_PROCESSING);

        // Préparer le remboursement total au locataire
        $deposit->setRefundAmountTenant($deposit->getEscrowedAmount());
        $deposit->setReleaseAmountLandlord('0');

        $this->entityManager->flush();

        $this->logger->info('[Escrow] Admin signature finale — remboursement en cours', [
            'id' => $deposit->getId(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 10. TRAITEMENT DU REMBOURSEMENT
    // ═════════════════════════════════════════════════════════

    /**
     * Traitement effectif du remboursement (appel API prestataire).
     * Status : refund_processing → completed
     */
    public function processRefund(
        SecureDeposit $deposit,
        ?string $refundTxId = null
    ): void {
        if ($deposit->getStatus() !== SecureDeposit::STATUS_REFUND_PROCESSING) {
            throw new \InvalidArgumentException('Le remboursement n\'est pas prêt');
        }

        $deposit->setRefundTransactionId($refundTxId);
        $deposit->setFundsReleasedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_COMPLETED);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Remboursement effectué — caution terminée', [
            'id' => $deposit->getId(),
            'refund_tx' => $refundTxId,
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // 11. LITIGE (pendant l'inspection)
    // ═════════════════════════════════════════════════════════

    /**
     * Le propriétaire ouvre un litige pendant l'inspection.
     */
    public function openDispute(
        SecureDeposit $deposit,
        User $landlord,
        array $data
    ): DepositDispute {
        if (!$deposit->canOpenDispute()) {
            throw new \InvalidArgumentException('Impossible d\'ouvrir un litige à ce stade');
        }

        $dispute = new DepositDispute();
        $dispute->setSecureDeposit($deposit);
        $dispute->setReportedBy($landlord);
        $dispute->setDamageDescription($data['damage_description']);
        $dispute->setEstimatedCost($data['estimated_cost']);

        if (isset($data['photos']) && is_array($data['photos'])) {
            $dispute->setPhotos($data['photos']);
        }
        if (isset($data['quote_document_url'])) {
            $dispute->setQuoteDocumentUrl($data['quote_document_url']);
        }

        $deposit->setStatus(SecureDeposit::STATUS_DISPUTE_OPEN);

        // Deadline 7 jours pour accord
        $deadline = new \DateTime('+7 days');
        $deposit->setDeadline7jAt($deadline);

        $this->entityManager->persist($dispute);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Litige ouvert', [
            'deposit_id' => $deposit->getId(),
            'dispute_id' => $dispute->getId(),
        ]);

        return $dispute;
    }

    /**
     * Le locataire accepte ou refuse le devis du litige.
     */
    public function respondToDispute(
        DepositDispute $dispute,
        bool $accepted,
        ?string $comment = null
    ): void {
        $dispute->setTenantRespondedAt(new \DateTime());
        $dispute->setTenantComment($comment);

        $deposit = $dispute->getSecureDeposit();

        if ($accepted) {
            $dispute->setStatus(DepositDispute::STATUS_TENANT_ACCEPTED);
            $deposit->setStatus(SecureDeposit::STATUS_DISPUTE_RESOLVED);

            $escrowed = (float) $deposit->getEscrowedAmount();
            $cost     = (float) $dispute->getEstimatedCost();
            $toLandlord = min($cost, $escrowed);
            $toTenant   = $escrowed - $toLandlord;

            $deposit->setReleaseAmountLandlord((string) $toLandlord);
            $deposit->setRefundAmountTenant((string) $toTenant);
        } else {
            $dispute->setStatus(DepositDispute::STATUS_TENANT_REFUSED);
        }

        $this->entityManager->flush();
    }

    // ═════════════════════════════════════════════════════════
    // 12. DÉBLOCAGE DES FONDS (split pour litiges résolus)
    // ═════════════════════════════════════════════════════════

    public function processSplitRelease(
        SecureDeposit $deposit,
        string $toTenant,
        string $toLandlord,
        ?string $refundTxId = null,
        ?string $payoutTxId = null
    ): void {
        $escrowed = (float) $deposit->getEscrowedAmount();
        $total    = (float) $toTenant + (float) $toLandlord;

        if (abs($total - $escrowed) > 0.01) {
            throw new \InvalidArgumentException(
                "Le total ($total) doit être égal au montant séquestré ($escrowed)"
            );
        }

        $deposit->setRefundAmountTenant($toTenant);
        $deposit->setReleaseAmountLandlord($toLandlord);
        $deposit->setRefundTransactionId($refundTxId);
        $deposit->setPayoutTransactionId($payoutTxId);
        $deposit->setFundsReleasedAt(new \DateTime());
        $deposit->setStatus(SecureDeposit::STATUS_COMPLETED);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Split release', [
            'id' => $deposit->getId(),
            'to_tenant' => $toTenant,
            'to_landlord' => $toLandlord,
        ]);
    }

    /**
     * Enregistre le choix de moyen de paiement pour déblocage.
     */
    public function setPayoutMethods(
        SecureDeposit $deposit,
        ?string $tenantMethod,
        ?string $landlordMethod
    ): void {
        $deposit->setTenantRefundMethod($tenantMethod);
        $deposit->setLandlordPayoutMethod($landlordMethod);
        $this->entityManager->flush();
    }

    // ═════════════════════════════════════════════════════════
    // 13. ANNULATION
    // ═════════════════════════════════════════════════════════

    /**
     * Annule un contrat qui n'a pas encore été payé.
     */
    public function cancelContract(SecureDeposit $deposit): void
    {
        $allowed = [
            SecureDeposit::STATUS_DRAFT,
            SecureDeposit::STATUS_SIGNED_TENANT,
            SecureDeposit::STATUS_SIGNED_LANDLORD,
            SecureDeposit::STATUS_PENDING_PAYMENT,
        ];

        if (!in_array($deposit->getStatus(), $allowed, true)) {
            throw new \InvalidArgumentException('Impossible d\'annuler un contrat déjà payé');
        }

        $deposit->setStatus(SecureDeposit::STATUS_CANCELLED);
        $this->entityManager->flush();

        $this->logger->info('[Escrow] Contrat annulé', ['id' => $deposit->getId()]);
    }

    // ═════════════════════════════════════════════════════════
    // 14. LECTURE
    // ═════════════════════════════════════════════════════════

    public function getByUser(int $userId): array
    {
        return $this->depositRepo->findByUser($userId);
    }

    public function getByListing(int $listingId): array
    {
        return $this->depositRepo->findByListing($listingId);
    }

    public function getAdminStats(): array
    {
        return $this->depositRepo->getAdminStats();
    }

    /**
     * Sérialise un SecureDeposit en tableau pour l'API JSON.
     */
    public function serialize(SecureDeposit $d, ?int $currentUserId = null): array
    {
        $listing  = $d->getListing();
        $tenant   = $d->getTenant();
        $landlord = $d->getLandlord();

        return [
            'id'                  => $d->getId(),
            'status'              => $d->getStatus(),
            'current_step'        => $d->getCurrentStep(),
            'is_fully_signed'     => $d->isFullySigned(),
            'is_exit_fully_signed'=> $d->isExitFullySigned(),
            'is_landlord'         => $currentUserId !== null && $landlord->getId() === $currentUserId,
            'is_tenant'           => $currentUserId !== null && $tenant->getId() === $currentUserId,
            'landlord_name'       => trim($landlord->getFirstName() . ' ' . $landlord->getLastName()),
            'tenant_name'         => trim($tenant->getFirstName() . ' ' . $tenant->getLastName()),
            'listing'             => [
                'id'    => $listing->getId(),
                'title' => $listing->getTitle(),
                'city'  => $listing->getCity(),
            ],
            'tenant'              => [
                'id'        => $tenant->getId(),
                'firstName' => $tenant->getFirstName(),
                'lastName'  => $tenant->getLastName(),
                'phone'     => $tenant->getPhone(),
            ],
            'landlord'            => [
                'id'        => $landlord->getId(),
                'firstName' => $landlord->getFirstName(),
                'lastName'  => $landlord->getLastName(),
                'phone'     => $landlord->getPhone(),
            ],
            'deposit_amount'      => $d->getDepositAmount(),
            'commission_amount'   => $d->getCommissionAmount(),
            'escrowed_amount'     => $d->getEscrowedAmount(),
            'payment_provider'    => $d->getPaymentProvider(),
            'payment_method'      => $d->getPaymentMethod(),
            'transaction_id'      => $d->getTransactionId(),
            'property_type'       => $d->getPropertyType(),
            'property_description'=> $d->getPropertyDescription(),
            'property_address'    => $d->getPropertyAddress(),
            'tenant_id_type'      => $d->getTenantIdType(),
            'tenant_id_number'    => $d->getTenantIdNumber(),
            'landlord_id_type'    => $d->getLandlordIdType(),
            'landlord_id_number'  => $d->getLandlordIdNumber(),
            'rental_start_date'   => $d->getRentalStartDate()?->format('Y-m-d'),
            'rental_end_date'     => $d->getRentalEndDate()?->format('Y-m-d'),
            'paid_at'             => $d->getPaidAt()?->format('Y-m-d H:i:s'),
            'end_of_rental_at'    => $d->getEndOfRentalAt()?->format('Y-m-d H:i:s'),
            'deadline_72h_at'     => $d->getDeadline72hAt()?->format('Y-m-d H:i:s'),
            'deadline_7j_at'      => $d->getDeadline7jAt()?->format('Y-m-d H:i:s'),
            // Signatures contrat initial
            'tenant_signed_at'             => $d->getTenantSignedAt()?->format('Y-m-d H:i:s'),
            'landlord_signed_at'           => $d->getLandlordSignedAt()?->format('Y-m-d H:i:s'),
            'admin_signed_at'              => $d->getAdminSignedAt()?->format('Y-m-d H:i:s'),
            // Processus de restitution
            'termination_requested_at'     => $d->getTerminationRequestedAt()?->format('Y-m-d H:i:s'),
            'admin_review_at'              => $d->getAdminReviewAt()?->format('Y-m-d H:i:s'),
            'landlord_inspection_at'       => $d->getLandlordInspectionAt()?->format('Y-m-d H:i:s'),
            'landlord_inspection_notes'    => $d->getLandlordInspectionNotes(),
            'landlord_exit_signed_at'      => $d->getLandlordExitSignedAt()?->format('Y-m-d H:i:s'),
            'tenant_exit_signed_at'        => $d->getTenantExitSignedAt()?->format('Y-m-d H:i:s'),
            'admin_final_signed_at'        => $d->getAdminFinalSignedAt()?->format('Y-m-d H:i:s'),
            // PDF & fonds
            'certificate_pdf_url'          => $d->getCertificatePdfUrl(),
            'refund_amount_tenant'         => $d->getRefundAmountTenant(),
            'release_amount_landlord'      => $d->getReleaseAmountLandlord(),
            'tenant_refund_method'         => $d->getTenantRefundMethod(),
            'landlord_payout_method'       => $d->getLandlordPayoutMethod(),
            'funds_released_at'            => $d->getFundsReleasedAt()?->format('Y-m-d H:i:s'),
            'disputes'            => array_map(fn(DepositDispute $dd) => [
                'id'                 => $dd->getId(),
                'damage_description' => $dd->getDamageDescription(),
                'estimated_cost'     => $dd->getEstimatedCost(),
                'photos'             => $dd->getPhotos(),
                'quote_document_url' => $dd->getQuoteDocumentUrl(),
                'status'             => $dd->getStatus(),
                'tenant_comment'     => $dd->getTenantComment(),
                'tenant_responded_at'=> $dd->getTenantRespondedAt()?->format('Y-m-d H:i:s'),
                'created_at'         => $dd->getCreatedAt()->format('Y-m-d H:i:s'),
            ], $d->getDisputes()->toArray()),
            'created_at'          => $d->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at'          => $d->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
