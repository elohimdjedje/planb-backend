<?php

namespace App\Entity;

use App\Repository\VerificationRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VerificationRequestRepository::class)]
#[ORM\Table(name: 'verification_request')]
#[ORM\Index(name: 'idx_verification_status', columns: ['status'])]
#[ORM\Index(name: 'idx_verification_user', columns: ['user_id'])]
class VerificationRequest
{
    // Statuts
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // Catégories de vérification
    public const CATEGORY_PARTICULIER = 'particulier';
    public const CATEGORY_BAILLEUR = 'bailleur';
    public const CATEGORY_VEHICULE = 'vehicule';
    public const CATEGORY_HOTEL = 'hotel';
    public const CATEGORY_MANUAL = 'manual';

    // Types de badges
    public const BADGE_IDENTITY = 'identity_verified';
    public const BADGE_BAILLEUR = 'bailleur_certified';
    public const BADGE_VEHICULE = 'vehicule_certified';
    public const BADGE_HOTEL = 'hotel_certified';
    public const BADGE_MANUAL = 'manual_certified';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 30)]
    private ?string $category = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::JSON)]
    private array $documents = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $attemptNumber = 1;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $badgeType = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $reviewedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $reviewedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $auditLog = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = self::STATUS_PENDING;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function setDocuments(array $documents): static
    {
        $this->documents = $documents;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function setAttemptNumber(int $attemptNumber): static
    {
        $this->attemptNumber = $attemptNumber;
        return $this;
    }

    public function getBadgeType(): ?string
    {
        return $this->badgeType;
    }

    public function setBadgeType(?string $badgeType): static
    {
        $this->badgeType = $badgeType;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getReviewedAt(): ?\DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeInterface $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    public function getReviewedBy(): ?int
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?int $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getAuditLog(): ?string
    {
        return $this->auditLog;
    }

    public function setAuditLog(?string $auditLog): static
    {
        $this->auditLog = $auditLog;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Détermine le type de badge selon la catégorie
     */
    public static function getBadgeForCategory(string $category): string
    {
        return match($category) {
            self::CATEGORY_PARTICULIER => self::BADGE_IDENTITY,
            self::CATEGORY_BAILLEUR => self::BADGE_BAILLEUR,
            self::CATEGORY_VEHICULE => self::BADGE_VEHICULE,
            self::CATEGORY_HOTEL => self::BADGE_HOTEL,
            self::CATEGORY_MANUAL => self::BADGE_MANUAL,
            default => self::BADGE_IDENTITY,
        };
    }

    /**
     * Documents requis par catégorie
     */
    public static function getRequiredDocuments(string $category): array
    {
        return match($category) {
            self::CATEGORY_PARTICULIER => ['cni_recto', 'cni_verso', 'selfie_with_id'],
            self::CATEGORY_BAILLEUR => ['cni_recto', 'cni_verso', 'property_document'],
            self::CATEGORY_VEHICULE => ['cni_recto', 'cni_verso', 'carte_grise'],
            self::CATEGORY_HOTEL => ['cni_gerant', 'registre_commerce', 'licence_exploitation'],
            default => ['cni_recto', 'cni_verso'],
        };
    }
}
