<?php

namespace App\Entity;

use App\Repository\UserDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserDocumentRepository::class)]
#[ORM\Table(name: 'user_documents')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_documents_user')]
#[ORM\Index(columns: ['status'], name: 'idx_user_documents_status')]
class UserDocument
{
    public const STATUS_UPLOADED = 'UPLOADED';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_EXPIRED = 'EXPIRED';

    public const DOC_TYPE_CNI = 'CNI';
    public const DOC_TYPE_PASSPORT = 'PASSPORT';
    public const DOC_TYPE_PERMIS = 'PERMIS';
    public const DOC_TYPE_CARTE_GRISE = 'CARTE_GRISE';
    public const DOC_TYPE_KBIS = 'KBIS';
    public const DOC_TYPE_RC_PRO = 'RC_PRO';
    public const DOC_TYPE_CARTE_PRO_IMMO = 'CARTE_PRO_IMMO';
    public const DOC_TYPE_DIPLOME = 'DIPLOME';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $docType = null;

    #[ORM\Column(type: 'text')]
    private ?string $fileUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_UPLOADED;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $validatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
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

    public function getDocType(): ?string
    {
        return $this->docType;
    }

    public function setDocType(string $docType): static
    {
        $this->docType = $docType;
        return $this;
    }

    public function getFileUrl(): ?string
    {
        return $this->fileUrl;
    }

    public function setFileUrl(string $fileUrl): static
    {
        $this->fileUrl = $fileUrl;
        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;
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

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getValidatedAt(): ?\DateTimeInterface
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeInterface $validatedAt): static
    {
        $this->validatedAt = $validatedAt;
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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function isValid(): bool
    {
        if ($this->status !== self::STATUS_VALIDATED) {
            return false;
        }
        if ($this->expiresAt && $this->expiresAt < new \DateTime()) {
            return false;
        }
        return true;
    }

    public function validate(\DateTimeInterface $expiresAt = null): static
    {
        $this->status = self::STATUS_VALIDATED;
        $this->validatedAt = new \DateTime();
        $this->expiresAt = $expiresAt;
        $this->rejectionReason = null;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function reject(string $reason): static
    {
        $this->status = self::STATUS_REJECTED;
        $this->rejectionReason = $reason;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'docType' => $this->docType,
            'fileName' => $this->fileName,
            'status' => $this->status,
            'rejectionReason' => $this->rejectionReason,
            'validatedAt' => $this->validatedAt?->format('c'),
            'expiresAt' => $this->expiresAt?->format('c'),
            'createdAt' => $this->createdAt->format('c'),
            'isValid' => $this->isValid(),
        ];
    }
}
