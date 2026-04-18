<?php

namespace App\Entity;

use App\Repository\DepositDisputeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Litige / réclamation de dommage sur un dépôt sécurisé.
 * Le bailleur upload des photos + devis, le locataire accepte ou refuse.
 */
#[ORM\Entity(repositoryClass: DepositDisputeRepository::class)]
#[ORM\Table(name: 'deposit_disputes')]
#[ORM\Index(columns: ['secure_deposit_id'], name: 'idx_dd_deposit')]
#[ORM\Index(columns: ['status'], name: 'idx_dd_status')]
class DepositDispute
{
    public const STATUS_PENDING         = 'pending';
    public const STATUS_TENANT_ACCEPTED = 'tenant_accepted';
    public const STATUS_TENANT_REFUSED  = 'tenant_refused';
    public const STATUS_AUTO_REFUNDED   = 'auto_refunded';
    public const STATUS_RESOLVED        = 'resolved';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SecureDeposit::class, inversedBy: 'disputes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SecureDeposit $secureDeposit = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $reportedBy = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La description des dommages est requise')]
    private ?string $damageDescription = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\Positive]
    private ?string $estimatedCost = null;

    /** @var string[] URLs des photos de dommages */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $photos = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $quoteDocumentUrl = null;

    #[ORM\Column(length: 25)]
    #[Assert\Choice(choices: [
        self::STATUS_PENDING,
        self::STATUS_TENANT_ACCEPTED,
        self::STATUS_TENANT_REFUSED,
        self::STATUS_AUTO_REFUNDED,
        self::STATUS_RESOLVED,
    ])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tenantComment = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $tenantRespondedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    // ── Getters / Setters ───────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getSecureDeposit(): ?SecureDeposit { return $this->secureDeposit; }
    public function setSecureDeposit(?SecureDeposit $v): static { $this->secureDeposit = $v; return $this; }

    public function getReportedBy(): ?User { return $this->reportedBy; }
    public function setReportedBy(?User $v): static { $this->reportedBy = $v; return $this; }

    public function getDamageDescription(): ?string { return $this->damageDescription; }
    public function setDamageDescription(string $v): static { $this->damageDescription = $v; return $this; }

    public function getEstimatedCost(): ?string { return $this->estimatedCost; }
    public function setEstimatedCost(string $v): static { $this->estimatedCost = $v; return $this; }

    public function getPhotos(): array { return $this->photos; }
    public function setPhotos(array $v): static { $this->photos = $v; return $this; }
    public function addPhoto(string $url): static { $this->photos[] = $url; return $this; }

    public function getQuoteDocumentUrl(): ?string { return $this->quoteDocumentUrl; }
    public function setQuoteDocumentUrl(?string $v): static { $this->quoteDocumentUrl = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getTenantComment(): ?string { return $this->tenantComment; }
    public function setTenantComment(?string $v): static { $this->tenantComment = $v; return $this; }

    public function getTenantRespondedAt(): ?\DateTimeInterface { return $this->tenantRespondedAt; }
    public function setTenantRespondedAt(?\DateTimeInterface $v): static { $this->tenantRespondedAt = $v; return $this; }

    public function getResolvedAt(): ?\DateTimeInterface { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeInterface $v): static { $this->resolvedAt = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
}
