<?php

namespace App\Entity;

use App\Repository\ScopeVerificationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScopeVerificationRepository::class)]
#[ORM\Table(name: 'scope_verifications')]
#[ORM\UniqueConstraint(name: 'unique_user_scope', columns: ['user_id', 'scope_key'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_scope_verifications_user')]
#[ORM\Index(columns: ['status'], name: 'idx_scope_verifications_status')]
#[ORM\Index(columns: ['scope_key'], name: 'idx_scope_verifications_scope')]
class ScopeVerification
{
    public const STATUS_NOT_STARTED = 'NOT_STARTED';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_BLOCKED = 'BLOCKED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $scopeKey = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_NOT_STARTED;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $submittedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reviewedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $approvedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: 'integer')]
    private int $rejectionCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $blockedUntil = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\ManyToMany(targetEntity: UserDocument::class)]
    #[ORM\JoinTable(name: 'scope_verification_documents')]
    private Collection $documents;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->documents = new ArrayCollection();
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

    public function getScopeKey(): ?string
    {
        return $this->scopeKey;
    }

    public function setScopeKey(string $scopeKey): static
    {
        $this->scopeKey = $scopeKey;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeInterface
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeInterface $submittedAt): static
    {
        $this->submittedAt = $submittedAt;
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

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeInterface $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getRejectionCount(): int
    {
        return $this->rejectionCount;
    }

    public function incrementRejectionCount(): static
    {
        $this->rejectionCount++;
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

    public function getBlockedUntil(): ?\DateTimeInterface
    {
        return $this->blockedUntil;
    }

    public function setBlockedUntil(?\DateTimeInterface $blockedUntil): static
    {
        $this->blockedUntil = $blockedUntil;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(UserDocument $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }
        return $this;
    }

    public function removeDocument(UserDocument $document): static
    {
        $this->documents->removeElement($document);
        return $this;
    }

    public function isApproved(): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }
        if ($this->expiresAt && $this->expiresAt < new \DateTime()) {
            return false;
        }
        return true;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isBlocked(): bool
    {
        if ($this->status === self::STATUS_BLOCKED) {
            return true;
        }
        if ($this->blockedUntil && $this->blockedUntil > new \DateTime()) {
            return true;
        }
        return false;
    }

    public function submit(): static
    {
        $this->status = self::STATUS_PENDING;
        $this->submittedAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function approve(User $reviewer, int $expirationDays = 730): static
    {
        $this->status = self::STATUS_APPROVED;
        $this->reviewedAt = new \DateTime();
        $this->reviewedBy = $reviewer;
        $this->approvedAt = new \DateTime();
        $this->expiresAt = (new \DateTime())->modify("+{$expirationDays} days");
        $this->rejectionReason = null;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function reject(User $reviewer, string $reason, int $maxRejections = 3, int $cooldownHours = 24): static
    {
        $this->status = self::STATUS_REJECTED;
        $this->reviewedAt = new \DateTime();
        $this->reviewedBy = $reviewer;
        $this->rejectionReason = $reason;
        $this->incrementRejectionCount();
        $this->updatedAt = new \DateTime();

        // Si trop de rejets, bloquer
        if ($this->rejectionCount >= $maxRejections) {
            $this->status = self::STATUS_BLOCKED;
            $this->blockedUntil = (new \DateTime())->modify('+30 days');
        }

        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scopeKey' => $this->scopeKey,
            'status' => $this->status,
            'submittedAt' => $this->submittedAt?->format('c'),
            'reviewedAt' => $this->reviewedAt?->format('c'),
            'approvedAt' => $this->approvedAt?->format('c'),
            'expiresAt' => $this->expiresAt?->format('c'),
            'rejectionCount' => $this->rejectionCount,
            'rejectionReason' => $this->rejectionReason,
            'blockedUntil' => $this->blockedUntil?->format('c'),
            'isApproved' => $this->isApproved(),
            'isPending' => $this->isPending(),
            'isBlocked' => $this->isBlocked(),
            'createdAt' => $this->createdAt->format('c'),
            'documents' => array_map(fn($d) => $d->toArray(), $this->documents->toArray()),
        ];
    }
}
