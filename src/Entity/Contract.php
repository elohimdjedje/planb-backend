<?php

namespace App\Entity;

use App\Repository\ContractRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'contracts')]
#[ORM\Index(columns: ['booking_id'], name: 'idx_contracts_booking')]
#[ORM\Index(columns: ['unique_contract_id'], name: 'idx_contracts_unique_id')]
#[ORM\Index(columns: ['status'], name: 'idx_contracts_status')]
class Contract
{
    // ── Machine à états ──
    public const STATUS_DRAFT         = 'draft';
    public const STATUS_TENANT_SIGNED = 'tenant_signed';
    public const STATUS_OWNER_SIGNED  = 'owner_signed';
    public const STATUS_LOCKED        = 'locked';
    public const STATUS_ARCHIVED      = 'archived';

    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_TENANT_SIGNED,
        self::STATUS_OWNER_SIGNED,
        self::STATUS_LOCKED,
        self::STATUS_ARCHIVED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant unique lisible (ex: PLANB-2026-00042) */
    #[ORM\Column(length: 50, unique: true, nullable: true)]
    private ?string $uniqueContractId = null;

    #[ORM\OneToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le type de template est requis')]
    #[Assert\Choice(
        choices: ['furnished_rental', 'unfurnished_rental', 'seasonal_rental', 'uploaded'],
        message: 'Type de template invalide'
    )]
    private ?string $templateType = null;

    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank(message: 'Les données du contrat sont requises')]
    private ?array $contractData = null;

    /** URL du PDF généré (version finale verrouillée) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pdfUrl = null;

    /** Chemin du PDF uploadé par le propriétaire */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $uploadedPdfPath = null;

    /** Hash SHA-256 du document (figé à la génération) */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $documentHash = null;

    /** Hash SHA-256 du document signé final (post-double-signature) */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $signedDocumentHash = null;

    // ── Signature locataire ──
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $tenantSignedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tenantSignatureUrl = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tenantSignatureMeta = null; // {uid, ip, user_agent, timestamp}

    // ── Signature propriétaire ──
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $ownerSignedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ownerSignatureUrl = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $ownerSignatureMeta = null; // {uid, ip, user_agent, timestamp}

    /** Date de verrouillage du document (post-double-signature) */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lockedAt = null;

    #[ORM\Column(length: 30, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    // ── Loyer + caution à payer ──
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $rentAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $depositMonthlyAmount = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $depositMonths = 1;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $totalPaymentAmount = null;

    // ── Paiement Kkiapay ──
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $paymentStatus = null; // payment_pending, payment_success, payment_failed

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $kkiapayTransactionId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $receiptUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $quittanceUrl = null;

    // ── Restitution ──
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $restitutionStatus = null; // restitution_requested, restitution_processing, restitution_validated, restitution_completed

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $restitutionNotes = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $restitutionRetainedAmount = null; // retenue partielle

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $restitutionRequestedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $restitutionCompletedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $exitReportUrl = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getUniqueContractId(): ?string { return $this->uniqueContractId; }
    public function setUniqueContractId(?string $v): static { $this->uniqueContractId = $v; return $this; }

    public function getBooking(): ?Booking { return $this->booking; }
    public function setBooking(?Booking $b): static { $this->booking = $b; return $this; }

    public function getTemplateType(): ?string { return $this->templateType; }
    public function setTemplateType(string $v): static { $this->templateType = $v; return $this; }

    public function getContractData(): ?array
    {
        return $this->contractData;
    }

    public function setContractData(array $contractData): static
    {
        $this->contractData = $contractData;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPdfUrl(): ?string { return $this->pdfUrl; }
    public function setPdfUrl(?string $v): static { $this->pdfUrl = $v; return $this; }

    public function getUploadedPdfPath(): ?string { return $this->uploadedPdfPath; }
    public function setUploadedPdfPath(?string $v): static { $this->uploadedPdfPath = $v; return $this; }

    public function getDocumentHash(): ?string { return $this->documentHash; }
    public function setDocumentHash(?string $v): static { $this->documentHash = $v; return $this; }

    public function getSignedDocumentHash(): ?string { return $this->signedDocumentHash; }
    public function setSignedDocumentHash(?string $v): static { $this->signedDocumentHash = $v; return $this; }

    // ── Locataire ──
    public function getTenantSignedAt(): ?\DateTimeInterface { return $this->tenantSignedAt; }
    public function setTenantSignedAt(?\DateTimeInterface $v): static { $this->tenantSignedAt = $v; return $this; }
    public function getTenantSignatureUrl(): ?string { return $this->tenantSignatureUrl; }
    public function setTenantSignatureUrl(?string $v): static { $this->tenantSignatureUrl = $v; return $this; }
    public function getTenantSignatureMeta(): ?array { return $this->tenantSignatureMeta; }
    public function setTenantSignatureMeta(?array $v): static { $this->tenantSignatureMeta = $v; return $this; }

    // ── Propriétaire ──
    public function getOwnerSignedAt(): ?\DateTimeInterface { return $this->ownerSignedAt; }
    public function setOwnerSignedAt(?\DateTimeInterface $v): static { $this->ownerSignedAt = $v; return $this; }
    public function getOwnerSignatureUrl(): ?string { return $this->ownerSignatureUrl; }
    public function setOwnerSignatureUrl(?string $v): static { $this->ownerSignatureUrl = $v; return $this; }
    public function getOwnerSignatureMeta(): ?array { return $this->ownerSignatureMeta; }
    public function setOwnerSignatureMeta(?array $v): static { $this->ownerSignatureMeta = $v; return $this; }

    // ── Verrouillage ──
    public function getLockedAt(): ?\DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?\DateTimeInterface $v): static { $this->lockedAt = $v; return $this; }

    // ── Statut ──
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; $this->updatedAt = new \DateTime(); return $this; }

    // ── Paiement ──
    public function getRentAmount(): ?string { return $this->rentAmount; }
    public function setRentAmount(?string $v): static { $this->rentAmount = $v; return $this; }
    public function getDepositMonthlyAmount(): ?string { return $this->depositMonthlyAmount; }
    public function setDepositMonthlyAmount(?string $v): static { $this->depositMonthlyAmount = $v; return $this; }
    public function getDepositMonths(): int { return $this->depositMonths; }
    public function setDepositMonths(int $v): static { $this->depositMonths = $v; return $this; }
    public function getTotalPaymentAmount(): ?string { return $this->totalPaymentAmount; }
    public function setTotalPaymentAmount(?string $v): static { $this->totalPaymentAmount = $v; return $this; }
    public function getPaymentStatus(): ?string { return $this->paymentStatus; }
    public function setPaymentStatus(?string $v): static { $this->paymentStatus = $v; $this->updatedAt = new \DateTime(); return $this; }
    public function getKkiapayTransactionId(): ?string { return $this->kkiapayTransactionId; }
    public function setKkiapayTransactionId(?string $v): static { $this->kkiapayTransactionId = $v; return $this; }
    public function getPaidAt(): ?\DateTimeInterface { return $this->paidAt; }
    public function setPaidAt(?\DateTimeInterface $v): static { $this->paidAt = $v; return $this; }
    public function getReceiptUrl(): ?string { return $this->receiptUrl; }
    public function setReceiptUrl(?string $v): static { $this->receiptUrl = $v; return $this; }
    public function getQuittanceUrl(): ?string { return $this->quittanceUrl; }
    public function setQuittanceUrl(?string $v): static { $this->quittanceUrl = $v; return $this; }

    // ── Restitution ──
    public function getRestitutionStatus(): ?string { return $this->restitutionStatus; }
    public function setRestitutionStatus(?string $v): static { $this->restitutionStatus = $v; $this->updatedAt = new \DateTime(); return $this; }
    public function getRestitutionNotes(): ?string { return $this->restitutionNotes; }
    public function setRestitutionNotes(?string $v): static { $this->restitutionNotes = $v; return $this; }
    public function getRestitutionRetainedAmount(): ?string { return $this->restitutionRetainedAmount; }
    public function setRestitutionRetainedAmount(?string $v): static { $this->restitutionRetainedAmount = $v; return $this; }
    public function getRestitutionRequestedAt(): ?\DateTimeInterface { return $this->restitutionRequestedAt; }
    public function setRestitutionRequestedAt(?\DateTimeInterface $v): static { $this->restitutionRequestedAt = $v; return $this; }
    public function getRestitutionCompletedAt(): ?\DateTimeInterface { return $this->restitutionCompletedAt; }
    public function setRestitutionCompletedAt(?\DateTimeInterface $v): static { $this->restitutionCompletedAt = $v; return $this; }
    public function getExitReportUrl(): ?string { return $this->exitReportUrl; }
    public function setExitReportUrl(?string $v): static { $this->exitReportUrl = $v; return $this; }

    // ── Dates ──
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $v): static { $this->updatedAt = $v; return $this; }

    // ── Helpers ──
    public function isTenantSigned(): bool { return $this->tenantSignedAt !== null; }
    public function isOwnerSigned(): bool  { return $this->ownerSignedAt !== null; }
    public function isFullySigned(): bool  { return $this->isTenantSigned() && $this->isOwnerSigned(); }
    public function isLocked(): bool       { return $this->status === self::STATUS_LOCKED; }
    public function isPayable(): bool      { return $this->isLocked() && $this->paymentStatus === null; }
    public function canTenantSign(): bool  { return $this->status === self::STATUS_DRAFT && !$this->isTenantSigned(); }
    public function canOwnerSign(): bool   { return $this->status === self::STATUS_TENANT_SIGNED && !$this->isOwnerSigned(); }

    /**
     * Calcule le total à payer (loyer + caution × mois)
     */
    public function computeTotal(): float
    {
        $rent    = (float) ($this->rentAmount ?? 0);
        $deposit = (float) ($this->depositMonthlyAmount ?? 0);
        return $rent + ($deposit * $this->depositMonths);
    }
}
