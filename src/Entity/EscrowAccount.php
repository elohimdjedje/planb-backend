<?php

namespace App\Entity;

use App\Repository\EscrowAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EscrowAccountRepository::class)]
#[ORM\Table(name: 'escrow_accounts')]
#[ORM\Index(columns: ['booking_id'], name: 'idx_escrow_booking')]
#[ORM\Index(columns: ['status'], name: 'idx_escrow_status')]
class EscrowAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant de la caution est requis')]
    #[Assert\Positive(message: 'Le montant de la caution doit être positif')]
    private ?string $depositAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant du premier loyer est requis')]
    #[Assert\Positive(message: 'Le montant du premier loyer doit être positif')]
    private ?string $firstRentAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant total bloqué est requis')]
    #[Assert\Positive(message: 'Le montant total bloqué doit être positif')]
    private ?string $totalHeld = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(
        choices: ['active', 'deposit_released', 'fully_released', 'disputed'],
        message: 'Statut invalide'
    )]
    private string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $depositHeldAt = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $depositReleaseDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $depositReleasedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstRentReleasedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $releaseReason = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->depositHeldAt = new \DateTime();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
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

    public function getDepositAmount(): ?string
    {
        return $this->depositAmount;
    }

    public function setDepositAmount(string $depositAmount): static
    {
        $this->depositAmount = $depositAmount;
        $this->calculateTotalHeld();
        return $this;
    }

    public function getFirstRentAmount(): ?string
    {
        return $this->firstRentAmount;
    }

    public function setFirstRentAmount(string $firstRentAmount): static
    {
        $this->firstRentAmount = $firstRentAmount;
        $this->calculateTotalHeld();
        return $this;
    }

    public function getTotalHeld(): ?string
    {
        return $this->totalHeld;
    }

    public function setTotalHeld(string $totalHeld): static
    {
        $this->totalHeld = $totalHeld;
        return $this;
    }

    private function calculateTotalHeld(): void
    {
        if ($this->depositAmount && $this->firstRentAmount) {
            $this->totalHeld = (string)((float)$this->depositAmount + (float)$this->firstRentAmount);
        }
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

    public function getDepositHeldAt(): ?\DateTimeInterface
    {
        return $this->depositHeldAt;
    }

    public function setDepositHeldAt(\DateTimeInterface $depositHeldAt): static
    {
        $this->depositHeldAt = $depositHeldAt;
        return $this;
    }

    public function getDepositReleaseDate(): ?\DateTimeInterface
    {
        return $this->depositReleaseDate;
    }

    public function setDepositReleaseDate(?\DateTimeInterface $depositReleaseDate): static
    {
        $this->depositReleaseDate = $depositReleaseDate;
        return $this;
    }

    public function getDepositReleasedAt(): ?\DateTimeInterface
    {
        return $this->depositReleasedAt;
    }

    public function setDepositReleasedAt(?\DateTimeInterface $depositReleasedAt): static
    {
        $this->depositReleasedAt = $depositReleasedAt;
        if ($depositReleasedAt) {
            $this->status = 'deposit_released';
        }
        return $this;
    }

    public function getFirstRentReleasedAt(): ?\DateTimeInterface
    {
        return $this->firstRentReleasedAt;
    }

    public function setFirstRentReleasedAt(?\DateTimeInterface $firstRentReleasedAt): static
    {
        $this->firstRentReleasedAt = $firstRentReleasedAt;
        if ($firstRentReleasedAt && $this->depositReleasedAt) {
            $this->status = 'fully_released';
        }
        return $this;
    }

    public function getReleaseReason(): ?string
    {
        return $this->releaseReason;
    }

    public function setReleaseReason(?string $releaseReason): static
    {
        $this->releaseReason = $releaseReason;
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

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Vérifie si la caution peut être libérée
     */
    public function canReleaseDeposit(): bool
    {
        return $this->status === 'active' && !$this->depositReleasedAt;
    }

    /**
     * Vérifie si le premier loyer peut être libéré
     */
    public function canReleaseFirstRent(): bool
    {
        return $this->status === 'active' && !$this->firstRentReleasedAt;
    }
}
