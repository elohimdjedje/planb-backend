<?php

namespace App\Entity;

use App\Repository\SaleContractRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compromis de vente sécurisé — séquestre du prix de vente
 *
 * Workflow :
 *  1. draft             → Contrat créé à l'acceptation de l'offre
 *  2. buyer_signed      → Acheteur signe le compromis
 *  3. seller_signed     → Vendeur signe → contrat verrouillé
 *  4. escrow_pending    → En attente du paiement séquestre (acheteur)
 *  5. escrow_funded     → Paiement reçu et sécurisé sur la plateforme
 *  6. completed         → Vente finalisée, listing → sold
 *  7. cancelled         → Transaction annulée
 */
#[ORM\Entity(repositoryClass: SaleContractRepository::class)]
#[ORM\Table(name: 'sale_contracts')]
#[ORM\Index(columns: ['offer_id'], name: 'idx_sc_offer')]
#[ORM\Index(columns: ['buyer_id'], name: 'idx_sc_buyer')]
#[ORM\Index(columns: ['seller_id'], name: 'idx_sc_seller')]
#[ORM\Index(columns: ['status'], name: 'idx_sc_status')]
class SaleContract
{
    public const STATUS_DRAFT          = 'draft';
    public const STATUS_BUYER_SIGNED   = 'buyer_signed';
    public const STATUS_SELLER_SIGNED  = 'seller_signed';
    public const STATUS_ESCROW_PENDING = 'escrow_pending';
    public const STATUS_ESCROW_FUNDED  = 'escrow_funded';
    public const STATUS_COMPLETED      = 'completed';
    public const STATUS_CANCELLED      = 'cancelled';

    /** Commission PlanB sur la vente (3 %) */
    public const COMMISSION_RATE = 0.03;

    /** Numéro d'étape pour le tracker visuel */
    public const STEP_MAP = [
        self::STATUS_DRAFT          => 1,
        self::STATUS_BUYER_SIGNED   => 2,
        self::STATUS_SELLER_SIGNED  => 3,
        self::STATUS_ESCROW_PENDING => 4,
        self::STATUS_ESCROW_FUNDED  => 5,
        self::STATUS_COMPLETED      => 6,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant lisible : PLANB-VENTE-2026-00042 */
    #[ORM\Column(length: 60, unique: true, nullable: true)]
    private ?string $uniqueContractId = null;

    #[ORM\OneToOne(targetEntity: Offer::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Offer $offer = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $buyer = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $seller = null;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Listing $listing = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private ?string $salePrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $commissionAmount = null;

    #[ORM\Column(length: 30, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    // ── Signature acheteur ──
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $buyerSignedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $buyerSignatureUrl = null;

    // ── Signature vendeur ──
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $sellerSignedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sellerSignatureUrl = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lockedAt = null;

    // ── Paiement séquestre (KKiaPay) ──
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $paymentStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $kkiapayTransactionId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    // ── Getters / Setters ──────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getUniqueContractId(): ?string { return $this->uniqueContractId; }
    public function setUniqueContractId(?string $v): static { $this->uniqueContractId = $v; return $this; }

    public function getOffer(): ?Offer { return $this->offer; }
    public function setOffer(?Offer $o): static { $this->offer = $o; return $this; }

    public function getBuyer(): ?User { return $this->buyer; }
    public function setBuyer(?User $u): static { $this->buyer = $u; return $this; }

    public function getSeller(): ?User { return $this->seller; }
    public function setSeller(?User $u): static { $this->seller = $u; return $this; }

    public function getListing(): ?Listing { return $this->listing; }
    public function setListing(?Listing $l): static { $this->listing = $l; return $this; }

    public function getSalePrice(): ?string { return $this->salePrice; }
    public function setSalePrice(?string $v): static { $this->salePrice = $v; return $this; }

    public function getCommissionAmount(): ?string { return $this->commissionAmount; }
    public function setCommissionAmount(?string $v): static { $this->commissionAmount = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; $this->updatedAt = new \DateTime(); return $this; }

    public function getBuyerSignedAt(): ?\DateTimeInterface { return $this->buyerSignedAt; }
    public function setBuyerSignedAt(?\DateTimeInterface $v): static { $this->buyerSignedAt = $v; return $this; }
    public function getBuyerSignatureUrl(): ?string { return $this->buyerSignatureUrl; }
    public function setBuyerSignatureUrl(?string $v): static { $this->buyerSignatureUrl = $v; return $this; }

    public function getSellerSignedAt(): ?\DateTimeInterface { return $this->sellerSignedAt; }
    public function setSellerSignedAt(?\DateTimeInterface $v): static { $this->sellerSignedAt = $v; return $this; }
    public function getSellerSignatureUrl(): ?string { return $this->sellerSignatureUrl; }
    public function setSellerSignatureUrl(?string $v): static { $this->sellerSignatureUrl = $v; return $this; }

    public function getLockedAt(): ?\DateTimeInterface { return $this->lockedAt; }
    public function setLockedAt(?\DateTimeInterface $v): static { $this->lockedAt = $v; return $this; }

    public function getPaymentStatus(): ?string { return $this->paymentStatus; }
    public function setPaymentStatus(?string $v): static { $this->paymentStatus = $v; $this->updatedAt = new \DateTime(); return $this; }

    public function getKkiapayTransactionId(): ?string { return $this->kkiapayTransactionId; }
    public function setKkiapayTransactionId(?string $v): static { $this->kkiapayTransactionId = $v; return $this; }

    public function getPaidAt(): ?\DateTimeInterface { return $this->paidAt; }
    public function setPaidAt(?\DateTimeInterface $v): static { $this->paidAt = $v; return $this; }

    public function getCompletedAt(): ?\DateTimeInterface { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeInterface $v): static { $this->completedAt = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }

    // ── Helpers ───────────────────────────────────────────────

    public function isBuyerSigned(): bool  { return $this->buyerSignedAt !== null; }
    public function isSellerSigned(): bool { return $this->sellerSignedAt !== null; }
    public function isFullySigned(): bool  { return $this->isBuyerSigned() && $this->isSellerSigned(); }
    public function isPayable(): bool      { return $this->status === self::STATUS_ESCROW_PENDING; }

    public function getCurrentStep(): int
    {
        return self::STEP_MAP[$this->status] ?? 1;
    }

    public function toArray(): array
    {
        $listing = $this->listing;
        return [
            'id'                   => $this->id,
            'uniqueContractId'     => $this->uniqueContractId,
            'offerId'              => $this->offer?->getId(),
            'listingId'            => $listing?->getId(),
            'listingTitle'         => $listing?->getTitle(),
            'listingAddress'       => $listing?->getAddress() ?? $listing?->getCity(),
            'listingPrice'         => $listing?->getPrice(),
            'buyerId'              => $this->buyer?->getId(),
            'buyerName'            => $this->buyer?->getFullName(),
            'buyerEmail'           => $this->buyer?->getEmail(),
            'buyerPhone'           => $this->buyer?->getPhone(),
            'sellerId'             => $this->seller?->getId(),
            'sellerName'           => $this->seller?->getFullName(),
            'sellerEmail'          => $this->seller?->getEmail(),
            'sellerPhone'          => $this->seller?->getPhone(),
            'salePrice'            => $this->salePrice,
            'commissionAmount'     => $this->commissionAmount,
            'status'               => $this->status,
            'currentStep'          => $this->getCurrentStep(),
            'buyerSignedAt'        => $this->buyerSignedAt?->format('Y-m-d H:i:s'),
            'sellerSignedAt'       => $this->sellerSignedAt?->format('Y-m-d H:i:s'),
            'lockedAt'             => $this->lockedAt?->format('Y-m-d H:i:s'),
            'paymentStatus'        => $this->paymentStatus,
            'kkiapayTransactionId' => $this->kkiapayTransactionId,
            'paidAt'               => $this->paidAt?->format('Y-m-d H:i:s'),
            'completedAt'          => $this->completedAt?->format('Y-m-d H:i:s'),
            'createdAt'            => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt'            => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
