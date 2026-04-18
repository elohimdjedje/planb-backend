<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\Index(columns: ['status'], name: 'idx_order_status')]
#[ORM\Index(columns: ['wave_session_id'], name: 'idx_wave_session')]
#[ORM\Index(columns: ['om_transaction_id'], name: 'idx_om_transaction')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $client = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $provider = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant est requis')]
    #[Assert\Positive(message: 'Le montant doit être positif')]
    private ?string $amount = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Choice(choices: ['wave', 'orange_money', 'free_money'], message: 'Moyen de paiement invalide')]
    private ?string $paymentMethod = null;

    // Pour Wave
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $waveSessionId = null;

    // Pour Orange Money
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $omTransactionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $omPaymentToken = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $apiStatus = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $apiCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiTransactionId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $apiTransactionDate = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending'; // pending, paid, cancelled, refunded

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Métadonnées pour stocker des informations additionnelles
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->metadata = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;
        return $this;
    }

    public function getProvider(): ?User
    {
        return $this->provider;
    }

    public function setProvider(?User $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    public function getWaveSessionId(): ?string
    {
        return $this->waveSessionId;
    }

    public function setWaveSessionId(?string $waveSessionId): static
    {
        $this->waveSessionId = $waveSessionId;
        return $this;
    }

    public function getOmTransactionId(): ?string
    {
        return $this->omTransactionId;
    }

    public function setOmTransactionId(?string $omTransactionId): static
    {
        $this->omTransactionId = $omTransactionId;
        return $this;
    }

    public function getOmPaymentToken(): ?string
    {
        return $this->omPaymentToken;
    }

    public function setOmPaymentToken(?string $omPaymentToken): static
    {
        $this->omPaymentToken = $omPaymentToken;
        return $this;
    }

    public function getApiStatus(): ?string
    {
        return $this->apiStatus;
    }

    public function setApiStatus(?string $apiStatus): static
    {
        $this->apiStatus = $apiStatus;
        return $this;
    }

    public function getApiCode(): ?string
    {
        return $this->apiCode;
    }

    public function setApiCode(?string $apiCode): static
    {
        $this->apiCode = $apiCode;
        return $this;
    }

    public function getApiTransactionId(): ?string
    {
        return $this->apiTransactionId;
    }

    public function setApiTransactionId(?string $apiTransactionId): static
    {
        $this->apiTransactionId = $apiTransactionId;
        return $this;
    }

    public function getApiTransactionDate(): ?\DateTimeInterface
    {
        return $this->apiTransactionDate;
    }

    public function setApiTransactionDate(?\DateTimeInterface $apiTransactionDate): static
    {
        $this->apiTransactionDate = $apiTransactionDate;
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

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'paid';
    }
}
