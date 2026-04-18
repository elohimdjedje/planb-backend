<?php

namespace App\Entity;

use App\Repository\BookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'bookings')]
#[ORM\Index(columns: ['listing_id'], name: 'idx_bookings_listing')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_bookings_tenant')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_bookings_owner')]
#[ORM\Index(columns: ['status'], name: 'idx_bookings_status')]
#[ORM\Index(columns: ['start_date', 'end_date'], name: 'idx_bookings_dates')]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Listing $listing = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Room $room = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de début est requise')]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de fin est requise')]
    #[Assert\Expression(
        'this.getEndDate() > this.getStartDate()',
        message: 'La date de fin doit être postérieure à la date de début'
    )]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $checkInDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $checkOutDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant total est requis')]
    #[Assert\Positive(message: 'Le montant total doit être positif')]
    private ?string $totalAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant de la caution est requis')]
    #[Assert\Positive(message: 'Le montant de la caution doit être positif')]
    private ?string $depositAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le loyer mensuel est requis')]
    #[Assert\Positive(message: 'Le loyer mensuel doit être positif')]
    private ?string $monthlyRent = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => 0])]
    private string $charges = '0';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(
        choices: ['pending', 'accepted', 'rejected', 'visited', 'confirmed', 'active', 'completed', 'cancelled'],
        message: 'Statut invalide'
    )]
    private string $status = 'pending';

    #[ORM\Column(options: ['default' => false])]
    private bool $depositPaid = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $firstRentPaid = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $depositReleased = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $requestedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $acceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tenantMessage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ownerResponse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTime();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getListing(): ?Listing
    {
        return $this->listing;
    }

    public function setListing(?Listing $listing): static
    {
        $this->listing = $listing;
        return $this;
    }

    public function getTenant(): ?User
    {
        return $this->tenant;
    }

    public function setTenant(?User $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function setRoom(?Room $room): static
    {
        $this->room = $room;
        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeInterface $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getCheckInDate(): ?\DateTimeInterface
    {
        return $this->checkInDate;
    }

    public function setCheckInDate(?\DateTimeInterface $checkInDate): static
    {
        $this->checkInDate = $checkInDate;
        return $this;
    }

    public function getCheckOutDate(): ?\DateTimeInterface
    {
        return $this->checkOutDate;
    }

    public function setCheckOutDate(?\DateTimeInterface $checkOutDate): static
    {
        $this->checkOutDate = $checkOutDate;
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

    public function getDepositAmount(): ?string
    {
        return $this->depositAmount;
    }

    public function setDepositAmount(string $depositAmount): static
    {
        $this->depositAmount = $depositAmount;
        return $this;
    }

    public function getMonthlyRent(): ?string
    {
        return $this->monthlyRent;
    }

    public function setMonthlyRent(string $monthlyRent): static
    {
        $this->monthlyRent = $monthlyRent;
        return $this;
    }

    public function getCharges(): string
    {
        return $this->charges;
    }

    public function setCharges(string $charges): static
    {
        $this->charges = $charges;
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

    public function isDepositPaid(): bool
    {
        return $this->depositPaid;
    }

    public function setDepositPaid(bool $depositPaid): static
    {
        $this->depositPaid = $depositPaid;
        return $this;
    }

    public function isFirstRentPaid(): bool
    {
        return $this->firstRentPaid;
    }

    public function setFirstRentPaid(bool $firstRentPaid): static
    {
        $this->firstRentPaid = $firstRentPaid;
        return $this;
    }

    public function isDepositReleased(): bool
    {
        return $this->depositReleased;
    }

    public function setDepositReleased(bool $depositReleased): static
    {
        $this->depositReleased = $depositReleased;
        return $this;
    }

    public function getRequestedAt(): ?\DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeInterface $requestedAt): static
    {
        $this->requestedAt = $requestedAt;
        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeInterface
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeInterface $acceptedAt): static
    {
        $this->acceptedAt = $acceptedAt;
        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeInterface
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeInterface $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;
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

    public function getTenantMessage(): ?string
    {
        return $this->tenantMessage;
    }

    public function setTenantMessage(?string $tenantMessage): static
    {
        $this->tenantMessage = $tenantMessage;
        return $this;
    }

    public function getOwnerResponse(): ?string
    {
        return $this->ownerResponse;
    }

    public function setOwnerResponse(?string $ownerResponse): static
    {
        $this->ownerResponse = $ownerResponse;
        return $this;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): static
    {
        $this->cancellationReason = $cancellationReason;
        return $this;
    }

    /**
     * Calcule le nombre de jours de location
     */
    public function getDurationInDays(): int
    {
        if (!$this->startDate || !$this->endDate) {
            return 0;
        }
        return $this->startDate->diff($this->endDate)->days;
    }

    /**
     * Vérifie si la réservation est active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Vérifie si la réservation peut être annulée
     */
    public function canBeCancelled(): bool
    {
        // 'confirmed' est exclu : la caution+loyer sont en escrow, une annulation
        // doit passer par BookingService::cancelBooking() qui gère le remboursement.
        return in_array($this->status, ['pending', 'accepted']);
    }
}
