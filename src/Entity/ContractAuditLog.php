<?php

namespace App\Entity;

use App\Repository\ContractAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'événements immuable pour les contrats.
 * Une fois créé, aucune mise à jour n'est possible.
 * Sert de preuve légale / traçabilité.
 */
#[ORM\Entity(repositoryClass: ContractAuditLogRepository::class)]
#[ORM\Table(name: 'contract_audit_logs')]
#[ORM\Index(columns: ['contract_id'], name: 'idx_audit_contract')]
#[ORM\Index(columns: ['user_id'],     name: 'idx_audit_user')]
#[ORM\Index(columns: ['event_type'],  name: 'idx_audit_event')]
#[ORM\Index(columns: ['created_at'],  name: 'idx_audit_date')]
class ContractAuditLog
{
    // Types d'événements possibles
    public const EVENT_CREATED             = 'contract.created';
    public const EVENT_PDF_GENERATED       = 'contract.pdf_generated';
    public const EVENT_PDF_UPLOADED        = 'contract.pdf_uploaded';
    public const EVENT_TENANT_SIGNED       = 'contract.tenant_signed';
    public const EVENT_OWNER_SIGNED        = 'contract.owner_signed';
    public const EVENT_LOCKED              = 'contract.locked';
    public const EVENT_PAYMENT_INITIATED   = 'payment.initiated';
    public const EVENT_PAYMENT_SUCCESS     = 'payment.success';
    public const EVENT_PAYMENT_FAILED      = 'payment.failed';
    public const EVENT_RESTITUTION_REQUESTED  = 'restitution.requested';
    public const EVENT_RESTITUTION_PROCESSED  = 'restitution.processed';
    public const EVENT_RESTITUTION_VALIDATED  = 'restitution.validated';
    public const EVENT_RESTITUTION_COMPLETED  = 'restitution.completed';
    public const EVENT_STATUS_CHANGE       = 'status.changed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Contract $contract = null;

    /** Utilisateur qui a déclenché l'événement (null = système) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 60)]
    private string $eventType;

    /** Description lisible de l'événement */
    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    /** Données contextuelles JSON (ex: ancien statut, nouveau statut, montant…) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    /** Hash SHA-256 du document au moment de l'événement */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $documentHash = null;

    /** Adresse IP de l'acteur */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** User-agent du navigateur */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    /** Hash de ce log (hash(id+contractId+eventType+createdAt) — pour intégrité) */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $logIntegrityHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters (pas de setters pour les champs immuables après création) ──

    public function getId(): ?int { return $this->id; }

    public function getContract(): ?Contract { return $this->contract; }
    public function setContract(Contract $contract): static { $this->contract = $contract; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getEventType(): string { return $this->eventType; }
    public function setEventType(string $v): static { $this->eventType = $v; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): static { $this->description = $v; return $this; }

    public function getContext(): ?array { return $this->context; }
    public function setContext(?array $v): static { $this->context = $v; return $this; }

    public function getDocumentHash(): ?string { return $this->documentHash; }
    public function setDocumentHash(?string $v): static { $this->documentHash = $v; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $v): static { $this->ipAddress = $v; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $v): static { $this->userAgent = $v; return $this; }

    public function getLogIntegrityHash(): ?string { return $this->logIntegrityHash; }
    public function setLogIntegrityHash(?string $v): static { $this->logIntegrityHash = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Calcule et stocke le hash d'intégrité de ce log.
     * Doit être appelé après setters, avant persist.
     */
    public function computeIntegrityHash(): void
    {
        $this->logIntegrityHash = hash('sha256',
            ($this->contract?->getId() ?? '') .
            ($this->user?->getId() ?? '') .
            $this->eventType .
            $this->description .
            ($this->documentHash ?? '') .
            $this->createdAt->format('Y-m-d H:i:s.u')
        );
    }
}
