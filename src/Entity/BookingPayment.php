<?php

namespace App\Entity;

use App\Repository\BookingPaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BookingPaymentRepository::class)]
#[ORM\Table(name: 'booking_payments')]
#[ORM\Index(columns: ['booking_id'], name: 'idx_booking_payments_booking')]
#[ORM\Index(columns: ['user_id'], name: 'idx_booking_payments_user')]
#[ORM\Index(columns: ['status'], name: 'idx_booking_payments_status')]
#[ORM\Index(columns: ['due_date'], name: 'idx_booking_payments_due_date')]
class BookingPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Le type de paiement est requis')]
    #[Assert\Choice(
        choices: ['deposit', 'first_rent', 'monthly_rent', 'charges', 'penalty', 'refund'],
        message: 'Type de paiement invalide'
    )]
    private ?string $type = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant est requis')]
    #[Assert\Positive(message: 'Le montant doit être positif')]
    private ?string $amount = null;

    #[ORM\Column(length: 3, options: ['default' => 'XOF'])]
    private string $currency = 'XOF';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(
        choices: ['pending', 'processing', 'completed', 'failed', 'refunded'],
        message: 'Statut invalide'
    )]
    private string $status = 'pending';

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'La méthode de paiement est requise')]
    #[Assert\Choice(
        choices: ['wave', 'orange_money', 'mtn_money', 'card', 'bank_transfer'],
        message: 'Méthode de paiement invalide'
    )]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = [];

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(?Booking $booking): static
    {
        $this->booking = $booking;
        return $this;
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        if ($status === 'completed' && !$this->paidAt) {
            $this->paidAt = new \DateTime();
        }
        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): static
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): static
    {
        $this->externalReference = $externalReference;
        return $this;
    }

    public function getDueDate(): ?\DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeInterface
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeInterface $paidAt): static
    {
        $this->paidAt = $paidAt;
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

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Vérifie si le paiement est en retard
     */
    public function isOverdue(): bool
    {
        if (!$this->dueDate || $this->status === 'completed') {
            return false;
        }
        return new \DateTime() > $this->dueDate;
    }

    /**
     * Calcule le nombre de jours de retard
     */
    public function getDaysOverdue(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }
        return (new \DateTime())->diff($this->dueDate)->days;
    }

    /**
     * Vérifie si le paiement est complété
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
