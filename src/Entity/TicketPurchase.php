<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'ticket_purchases')]
#[ORM\Index(columns: ['status'], name: 'idx_ticket_purchase_status')]
#[ORM\UniqueConstraint(columns: ['qr_code'], name: 'idx_ticket_purchase_qr')]
class TicketPurchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Event $event = null;

    #[ORM\ManyToOne(targetEntity: TicketType::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?TicketType $ticketType = null;

    #[ORM\Column]
    #[Assert\Positive(message: 'La quantité doit être positive')]
    private int $quantity = 1;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $serviceFee = null;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Payment $payment = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $qrCode = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du participant est requis')]
    private ?string $attendeeName = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'L\'email du participant est requis')]
    #[Assert\Email(message: 'Email invalide')]
    private ?string $attendeeEmail = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Le téléphone du participant est requis')]
    private ?string $attendeePhone = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['pending', 'confirmed', 'used', 'refunded', 'cancelled'], message: 'Statut invalide')]
    private string $status = 'pending';

    #[ORM\Column]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $usedAt = null;

    public function __construct()
    {
        $this->purchasedAt = new \DateTimeImmutable();
        $this->generateQrCode();
    }

    private function generateQrCode(): void
    {
        // Generate a unique secure hash for QR code
        $this->qrCode = hash('sha256', uniqid('ticket_', true) . random_bytes(16));
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

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): static
    {
        $this->event = $event;
        return $this;
    }

    public function getTicketType(): ?TicketType
    {
        return $this->ticketType;
    }

    public function setTicketType(?TicketType $ticketType): static
    {
        $this->ticketType = $ticketType;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getTotalAmount(): ?string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): static
    {
        $this->totalAmount = $totalAmount;
        return $this;
    }

    public function getServiceFee(): ?string
    {
        return $this->serviceFee;
    }

    public function setServiceFee(string $serviceFee): static
    {
        $this->serviceFee = $serviceFee;
        return $this;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(?Payment $payment): static
    {
        $this->payment = $payment;
        return $this;
    }

    public function getQrCode(): ?string
    {
        return $this->qrCode;
    }

    public function setQrCode(string $qrCode): static
    {
        $this->qrCode = $qrCode;
        return $this;
    }

    public function getAttendeeName(): ?string
    {
        return $this->attendeeName;
    }

    public function setAttendeeName(string $attendeeName): static
    {
        $this->attendeeName = $attendeeName;
        return $this;
    }

    public function getAttendeeEmail(): ?string
    {
        return $this->attendeeEmail;
    }

    public function setAttendeeEmail(string $attendeeEmail): static
    {
        $this->attendeeEmail = $attendeeEmail;
        return $this;
    }

    public function getAttendeePhone(): ?string
    {
        return $this->attendeePhone;
    }

    public function setAttendeePhone(string $attendeePhone): static
    {
        $this->attendeePhone = $attendeePhone;
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

    public function getPurchasedAt(): ?\DateTimeImmutable
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(\DateTimeImmutable $purchasedAt): static
    {
        $this->purchasedAt = $purchasedAt;
        return $this;
    }

    public function getUsedAt(): ?\DateTimeInterface
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeInterface $usedAt): static
    {
        $this->usedAt = $usedAt;
        return $this;
    }

    public function markAsUsed(): static
    {
        $this->status = 'used';
        $this->usedAt = new \DateTime();
        return $this;
    }

    public function isValid(): bool
    {
        return $this->status === 'confirmed' && 
               $this->event->getEventDate() > new \DateTime();
    }
}
