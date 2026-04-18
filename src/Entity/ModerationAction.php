<?php

namespace App\Entity;

use App\Repository\ModerationActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModerationActionRepository::class)]
#[ORM\Table(name: 'moderation_actions')]
#[ORM\Index(columns: ['moderator_id'], name: 'idx_moderation_moderator')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'idx_moderation_target')]
#[ORM\Index(columns: ['action_type'], name: 'idx_moderation_action_type')]
#[ORM\Index(columns: ['created_at'], name: 'idx_moderation_created')]
class ModerationAction
{
    // Types d'actions
    public const ACTION_HIDE = 'hide'; // Masquer
    public const ACTION_DELETE = 'delete'; // Supprimer
    public const ACTION_WARN = 'warn'; // Avertir
    public const ACTION_SUSPEND = 'suspend'; // Suspendre temporairement
    public const ACTION_BAN = 'ban'; // Bannir définitivement
    public const ACTION_UNBAN = 'unban'; // Débannir
    public const ACTION_APPROVE = 'approve'; // Approuver (rejeter le signalement)

    // Types de cibles
    public const TARGET_LISTING = 'listing';
    public const TARGET_USER = 'user';
    public const TARGET_MESSAGE = 'message';
    public const TARGET_REVIEW = 'review';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $moderator = null; // Modérateur qui a effectué l'action

    #[ORM\Column(length: 50)]
    private ?string $actionType = null; // hide, delete, warn, suspend, ban, unban, approve

    #[ORM\Column(length: 50)]
    private ?string $targetType = null; // listing, user, message, review

    #[ORM\Column]
    private ?int $targetId = null; // ID de la cible

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null; // Raison de l'action

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null; // Notes internes

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null; // Données additionnelles (durée suspension, etc.)

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Report $relatedReport = null; // Signalement lié (si applicable)

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null; // Pour suspensions temporaires

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModerator(): ?User
    {
        return $this->moderator;
    }

    public function setModerator(?User $moderator): static
    {
        $this->moderator = $moderator;
        return $this;
    }

    public function getActionType(): ?string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): static
    {
        $this->actionType = $actionType;
        return $this;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): static
    {
        $this->targetType = $targetType;
        return $this;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function setTargetId(int $targetId): static
    {
        $this->targetId = $targetId;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;
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

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getRelatedReport(): ?Report
    {
        return $this->relatedReport;
    }

    public function setRelatedReport(?Report $relatedReport): static
    {
        $this->relatedReport = $relatedReport;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
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

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
    }
}


