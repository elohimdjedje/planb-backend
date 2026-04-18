<?php

namespace App\Entity;

use App\Repository\LatePaymentPenaltyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LatePaymentPenaltyRepository::class)]
#[ORM\Table(name: 'late_payment_penalties')]
#[ORM\Index(columns: ['payment_id'], name: 'idx_penalties_payment')]
#[ORM\Index(columns: ['booking_id'], name: 'idx_penalties_booking')]
class LatePaymentPenalty
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookingPayment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BookingPayment $payment = null;

    #[ORM\ManyToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Le nombre de jours de retard est requis')]
    #[Assert\Positive(message: 'Le nombre de jours doit être positif')]
    private ?int $daysLate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'Le taux de pénalité est requis')]
    #[Assert\Range(
        min: 0,
        max: 100,
        notInRangeMessage: 'Le taux de pénalité doit être entre 0 et 100%'
    )]
    private ?string $penaltyRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant de la pénalité est requis')]
    #[Assert\Positive(message: 'Le montant de la pénalité doit être positif')]
    private ?string $penaltyAmount = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    #[Assert\Choice(
        choices: ['pending', 'paid', 'waived'],
        message: 'Statut invalide'
    )]
    private string $status = 'pending';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $calculatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    public function __construct()
    {
        $this->calculatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): ?BookingPayment
    {
        return $this->payment;
    }

    public function setPayment(?BookingPayment $payment): static
    {
        $this->payment = $payment;
        return $this;
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

    public function getDaysLate(): ?int
    {
        return $this->daysLate;
    }

    public function setDaysLate(int $daysLate): static
    {
        $this->daysLate = $daysLate;
        return $this;
    }

    public function getPenaltyRate(): ?string
    {
        return $this->penaltyRate;
    }

    public function setPenaltyRate(string $penaltyRate): static
    {
        $this->penaltyRate = $penaltyRate;
        return $this;
    }

    public function getPenaltyAmount(): ?string
    {
        return $this->penaltyAmount;
    }

    public function setPenaltyAmount(string $penaltyAmount): static
    {
        $this->penaltyAmount = $penaltyAmount;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        if ($status === 'paid' && !$this->paidAt) {
            $this->paidAt = new \DateTime();
        }
        return $this;
    }

    public function getCalculatedAt(): ?\DateTimeInterface
    {
        return $this->calculatedAt;
    }

    public function setCalculatedAt(\DateTimeInterface $calculatedAt): static
    {
        $this->calculatedAt = $calculatedAt;
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

    /**
     * Vérifie si la pénalité est payée
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Vérifie si la pénalité est annulée
     */
    public function isWaived(): bool
    {
        return $this->status === 'waived';
    }
}
