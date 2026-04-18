<?php

namespace App\Entity;

use App\Repository\SecureDepositRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Caution sécurisée (Escrow) — séquestre de caution locative
 *
 * Workflow complet (toutes les signatures se font sur l'application) :
 *  1. draft               → Le locataire crée le contrat et signe
 *  2. signed_tenant        → Envoyé au propriétaire qui signe
 *  3. signed_landlord      → Envoyé à l'admin qui signe
 *  4. pending_payment      → Demande de paiement envoyée au locataire
 *  5. active               → Caution payée, le locataire occupe le bien
 *  6. termination_requested→ Le locataire demande la résiliation et récupération de caution
 *  7. admin_review         → L'admin traite la demande et l'envoie au propriétaire
 *  8. landlord_inspection  → Le propriétaire inspecte le bien et fait les mises au point
 *  9. landlord_validated   → Le propriétaire valide et signe sur la plateforme
 * 10. tenant_exit_validated→ Le locataire signe le document de restitution
 * 11. refund_processing    → L'admin signe, valide et déclenche le remboursement
 * 12. completed            → Caution restituée au locataire
 */
#[ORM\Entity(repositoryClass: SecureDepositRepository::class)]
#[ORM\Table(name: 'secure_deposits')]
#[ORM\Index(columns: ['status'], name: 'idx_sd_status')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_sd_tenant')]
#[ORM\Index(columns: ['landlord_id'], name: 'idx_sd_landlord')]
#[ORM\Index(columns: ['listing_id'], name: 'idx_sd_listing')]
class SecureDeposit
{
    // ── Statuts du cycle de vie ──────────────────────────────

    // Phase 1 : Création et signatures du contrat
    public const STATUS_DRAFT              = 'draft';
    public const STATUS_SIGNED_TENANT      = 'signed_tenant';
    public const STATUS_SIGNED_LANDLORD    = 'signed_landlord';

    // Phase 2 : Paiement
    public const STATUS_PENDING_PAYMENT    = 'pending_payment';
    public const STATUS_ACTIVE             = 'active';

    // Phase 3 : Résiliation et restitution
    public const STATUS_TERMINATION_REQ    = 'termination_requested';
    public const STATUS_ADMIN_REVIEW       = 'admin_review';
    public const STATUS_LANDLORD_INSPECTION= 'landlord_inspection';
    public const STATUS_LANDLORD_VALIDATED = 'landlord_validated';
    public const STATUS_TENANT_EXIT_VALID  = 'tenant_exit_validated';
    public const STATUS_REFUND_PROCESSING  = 'refund_processing';
    public const STATUS_COMPLETED          = 'completed';

    // États spéciaux
    public const STATUS_DISPUTE_OPEN       = 'dispute_open';
    public const STATUS_DISPUTE_RESOLVED   = 'dispute_resolved';
    public const STATUS_CANCELLED          = 'cancelled';

    public const COMMISSION_RATE = 0.05; // 5 %

    public const VALID_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SIGNED_TENANT, self::STATUS_SIGNED_LANDLORD,
        self::STATUS_PENDING_PAYMENT, self::STATUS_ACTIVE,
        self::STATUS_TERMINATION_REQ, self::STATUS_ADMIN_REVIEW,
        self::STATUS_LANDLORD_INSPECTION, self::STATUS_LANDLORD_VALIDATED,
        self::STATUS_TENANT_EXIT_VALID, self::STATUS_REFUND_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_DISPUTE_OPEN, self::STATUS_DISPUTE_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    public const PROPERTY_TYPES   = ['maison', 'appartement', 'bureau', 'vehicule'];
    public const PAYMENT_METHODS  = ['orange_money','mtn_momo','moov_money','wave','paytech','kkiapay','fedapay','card'];

    /** Numéro d'étape pour le tracker visuel côté frontend */
    public const STEP_MAP = [
        self::STATUS_DRAFT              => 1,
        self::STATUS_SIGNED_TENANT      => 2,
        self::STATUS_SIGNED_LANDLORD    => 3,
        self::STATUS_PENDING_PAYMENT    => 4,
        self::STATUS_ACTIVE             => 5,
        self::STATUS_TERMINATION_REQ    => 6,
        self::STATUS_ADMIN_REVIEW       => 7,
        self::STATUS_LANDLORD_INSPECTION=> 8,
        self::STATUS_LANDLORD_VALIDATED => 9,
        self::STATUS_TENANT_EXIT_VALID  => 10,
        self::STATUS_REFUND_PROCESSING  => 11,
        self::STATUS_COMPLETED          => 12,
    ];

    // ── Champs ──────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Listing $listing = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $landlord = null;

    // ── Montants ────────────────────────────────────────────

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank] #[Assert\Positive]
    private ?string $depositAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $commissionAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $escrowedAmount = null;

    // ── Paiement ────────────────────────────────────────────

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $paymentProvider = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentUrl = null;

    // ── Statut ──────────────────────────────────────────────

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::VALID_STATUSES)]
    private string $status = self::STATUS_DRAFT;

    // ── Bien loué ───────────────────────────────────────────

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::PROPERTY_TYPES)]
    private ?string $propertyType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $propertyDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $propertyAddress = null;

    // ── Pièces d'identité ───────────────────────────────────

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tenantIdType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tenantIdNumber = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $landlordIdType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $landlordIdNumber = null;

    // ── Dates de location ───────────────────────────────────

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $rentalStartDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $rentalEndDate = null;

    // ── Dates du workflow ───────────────────────────────────

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $endOfRentalAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deadline72hAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deadline7jAt = null;

    // ── Signatures contrat initial ──────────────────────────

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $tenantSignedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $landlordSignedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $adminSignedAt = null;

    // ── Processus de restitution ────────────────────────────

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $terminationRequestedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $adminReviewAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $landlordInspectionAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $landlordInspectionNotes = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $landlordExitSignedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $tenantExitSignedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $adminFinalSignedAt = null;

    // ── Certificat PDF ──────────────────────────────────────

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $certificatePdfUrl = null;

    // ── Déblocage des fonds ─────────────────────────────────

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $refundAmountTenant = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $releaseAmountLandlord = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $tenantRefundMethod = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $landlordPayoutMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $refundTransactionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payoutTransactionId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $fundsReleasedAt = null;

    // ── Relations ───────────────────────────────────────────

    /** @var Collection<int, DepositDispute> */
    #[ORM\OneToMany(targetEntity: DepositDispute::class, mappedBy: 'secureDeposit', cascade: ['persist', 'remove'])]
    private Collection $disputes;

    // ── Timestamps ──────────────────────────────────────────

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    // ═════════════════════════════════════════════════════════

    public function __construct()
    {
        $this->disputes  = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    // ── Helpers ─────────────────────────────────────────────

    public function calculateAmounts(): static
    {
        if ($this->depositAmount) {
            $total = (float) $this->depositAmount;
            $this->commissionAmount = (string) round($total * self::COMMISSION_RATE, 2);
            $this->escrowedAmount   = (string) round($total - (float) $this->commissionAmount, 2);
        }
        return $this;
    }

    public function isFullySigned(): bool
    {
        return $this->tenantSignedAt !== null && $this->landlordSignedAt !== null && $this->adminSignedAt !== null;
    }

    public function isContractReadyForPayment(): bool
    {
        return $this->isFullySigned() && $this->status === self::STATUS_PENDING_PAYMENT;
    }

    public function canRequestTermination(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function canOpenDispute(): bool
    {
        return $this->status === self::STATUS_LANDLORD_INSPECTION;
    }

    public function getCurrentStep(): int
    {
        return self::STEP_MAP[$this->status] ?? 0;
    }

    public function isExitFullySigned(): bool
    {
        return $this->landlordExitSignedAt !== null && $this->tenantExitSignedAt !== null && $this->adminFinalSignedAt !== null;
    }

    // ── Getters / Setters ───────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getListing(): ?Listing { return $this->listing; }
    public function setListing(?Listing $listing): static { $this->listing = $listing; return $this; }

    public function getTenant(): ?User { return $this->tenant; }
    public function setTenant(?User $tenant): static { $this->tenant = $tenant; return $this; }

    public function getLandlord(): ?User { return $this->landlord; }
    public function setLandlord(?User $landlord): static { $this->landlord = $landlord; return $this; }

    public function getDepositAmount(): ?string { return $this->depositAmount; }
    public function setDepositAmount(string $v): static { $this->depositAmount = $v; $this->calculateAmounts(); return $this; }

    public function getCommissionAmount(): ?string { return $this->commissionAmount; }
    public function getEscrowedAmount(): ?string { return $this->escrowedAmount; }

    public function getPaymentProvider(): ?string { return $this->paymentProvider; }
    public function setPaymentProvider(?string $v): static { $this->paymentProvider = $v; return $this; }
    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $v): static { $this->paymentMethod = $v; return $this; }
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function setTransactionId(?string $v): static { $this->transactionId = $v; return $this; }
    public function getPaymentUrl(): ?string { return $this->paymentUrl; }
    public function setPaymentUrl(?string $v): static { $this->paymentUrl = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; $this->updatedAt = new \DateTime(); return $this; }

    public function getPropertyType(): ?string { return $this->propertyType; }
    public function setPropertyType(string $v): static { $this->propertyType = $v; return $this; }
    public function getPropertyDescription(): ?string { return $this->propertyDescription; }
    public function setPropertyDescription(?string $v): static { $this->propertyDescription = $v; return $this; }
    public function getPropertyAddress(): ?string { return $this->propertyAddress; }
    public function setPropertyAddress(?string $v): static { $this->propertyAddress = $v; return $this; }

    public function getTenantIdType(): ?string { return $this->tenantIdType; }
    public function setTenantIdType(?string $v): static { $this->tenantIdType = $v; return $this; }
    public function getTenantIdNumber(): ?string { return $this->tenantIdNumber; }
    public function setTenantIdNumber(?string $v): static { $this->tenantIdNumber = $v; return $this; }
    public function getLandlordIdType(): ?string { return $this->landlordIdType; }
    public function setLandlordIdType(?string $v): static { $this->landlordIdType = $v; return $this; }
    public function getLandlordIdNumber(): ?string { return $this->landlordIdNumber; }
    public function setLandlordIdNumber(?string $v): static { $this->landlordIdNumber = $v; return $this; }

    public function getRentalStartDate(): ?\DateTimeInterface { return $this->rentalStartDate; }
    public function setRentalStartDate(?\DateTimeInterface $v): static { $this->rentalStartDate = $v; return $this; }
    public function getRentalEndDate(): ?\DateTimeInterface { return $this->rentalEndDate; }
    public function setRentalEndDate(?\DateTimeInterface $v): static { $this->rentalEndDate = $v; return $this; }

    public function getPaidAt(): ?\DateTimeInterface { return $this->paidAt; }
    public function setPaidAt(?\DateTimeInterface $v): static { $this->paidAt = $v; return $this; }
    public function getEndOfRentalAt(): ?\DateTimeInterface { return $this->endOfRentalAt; }
    public function setEndOfRentalAt(?\DateTimeInterface $v): static { $this->endOfRentalAt = $v; return $this; }
    public function getDeadline72hAt(): ?\DateTimeInterface { return $this->deadline72hAt; }
    public function setDeadline72hAt(?\DateTimeInterface $v): static { $this->deadline72hAt = $v; return $this; }
    public function getDeadline7jAt(): ?\DateTimeInterface { return $this->deadline7jAt; }
    public function setDeadline7jAt(?\DateTimeInterface $v): static { $this->deadline7jAt = $v; return $this; }

    public function getTenantSignedAt(): ?\DateTimeInterface { return $this->tenantSignedAt; }
    public function setTenantSignedAt(?\DateTimeInterface $v): static { $this->tenantSignedAt = $v; return $this; }
    public function getLandlordSignedAt(): ?\DateTimeInterface { return $this->landlordSignedAt; }
    public function setLandlordSignedAt(?\DateTimeInterface $v): static { $this->landlordSignedAt = $v; return $this; }
    public function getAdminSignedAt(): ?\DateTimeInterface { return $this->adminSignedAt; }
    public function setAdminSignedAt(?\DateTimeInterface $v): static { $this->adminSignedAt = $v; return $this; }

    public function getTerminationRequestedAt(): ?\DateTimeInterface { return $this->terminationRequestedAt; }
    public function setTerminationRequestedAt(?\DateTimeInterface $v): static { $this->terminationRequestedAt = $v; return $this; }
    public function getAdminReviewAt(): ?\DateTimeInterface { return $this->adminReviewAt; }
    public function setAdminReviewAt(?\DateTimeInterface $v): static { $this->adminReviewAt = $v; return $this; }
    public function getLandlordInspectionAt(): ?\DateTimeInterface { return $this->landlordInspectionAt; }
    public function setLandlordInspectionAt(?\DateTimeInterface $v): static { $this->landlordInspectionAt = $v; return $this; }
    public function getLandlordInspectionNotes(): ?string { return $this->landlordInspectionNotes; }
    public function setLandlordInspectionNotes(?string $v): static { $this->landlordInspectionNotes = $v; return $this; }
    public function getLandlordExitSignedAt(): ?\DateTimeInterface { return $this->landlordExitSignedAt; }
    public function setLandlordExitSignedAt(?\DateTimeInterface $v): static { $this->landlordExitSignedAt = $v; return $this; }
    public function getTenantExitSignedAt(): ?\DateTimeInterface { return $this->tenantExitSignedAt; }
    public function setTenantExitSignedAt(?\DateTimeInterface $v): static { $this->tenantExitSignedAt = $v; return $this; }
    public function getAdminFinalSignedAt(): ?\DateTimeInterface { return $this->adminFinalSignedAt; }
    public function setAdminFinalSignedAt(?\DateTimeInterface $v): static { $this->adminFinalSignedAt = $v; return $this; }

    public function getCertificatePdfUrl(): ?string { return $this->certificatePdfUrl; }
    public function setCertificatePdfUrl(?string $v): static { $this->certificatePdfUrl = $v; return $this; }

    public function getRefundAmountTenant(): ?string { return $this->refundAmountTenant; }
    public function setRefundAmountTenant(?string $v): static { $this->refundAmountTenant = $v; return $this; }
    public function getReleaseAmountLandlord(): ?string { return $this->releaseAmountLandlord; }
    public function setReleaseAmountLandlord(?string $v): static { $this->releaseAmountLandlord = $v; return $this; }
    public function getTenantRefundMethod(): ?string { return $this->tenantRefundMethod; }
    public function setTenantRefundMethod(?string $v): static { $this->tenantRefundMethod = $v; return $this; }
    public function getLandlordPayoutMethod(): ?string { return $this->landlordPayoutMethod; }
    public function setLandlordPayoutMethod(?string $v): static { $this->landlordPayoutMethod = $v; return $this; }
    public function getRefundTransactionId(): ?string { return $this->refundTransactionId; }
    public function setRefundTransactionId(?string $v): static { $this->refundTransactionId = $v; return $this; }
    public function getPayoutTransactionId(): ?string { return $this->payoutTransactionId; }
    public function setPayoutTransactionId(?string $v): static { $this->payoutTransactionId = $v; return $this; }
    public function getFundsReleasedAt(): ?\DateTimeInterface { return $this->fundsReleasedAt; }
    public function setFundsReleasedAt(?\DateTimeInterface $v): static { $this->fundsReleasedAt = $v; return $this; }

    /** @return Collection<int, DepositDispute> */
    public function getDisputes(): Collection { return $this->disputes; }

    public function addDispute(DepositDispute $d): static
    {
        if (!$this->disputes->contains($d)) {
            $this->disputes->add($d);
            $d->setSecureDeposit($this);
        }
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $v): static { $this->updatedAt = $v; return $this; }
}
