<?php

namespace App\Entity;

use App\Repository\VisitSlotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Créneaux de disponibilité pour les visites guidées
 */
#[ORM\Entity(repositoryClass: VisitSlotRepository::class)]
#[ORM\Table(name: 'visit_slots')]
#[ORM\Index(columns: ['listing_id'], name: 'idx_visit_listing')]
#[ORM\Index(columns: ['date'], name: 'idx_visit_date')]
#[ORM\Index(columns: ['status'], name: 'idx_visit_status')]
#[ORM\Index(columns: ['listing_id', 'date', 'status'], name: 'idx_visit_available')]
class VisitSlot
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Listing $listing = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date est requise')]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    #[Assert\NotBlank(message: 'L\'heure de début est requise')]
    private ?\DateTimeInterface $startTime = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    #[Assert\NotBlank(message: 'L\'heure de fin est requise')]
    private ?\DateTimeInterface $endTime = null;

    #[ORM\Column(length: 20, options: ['default' => 'available'])]
    private string $status = self::STATUS_AVAILABLE;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $bookedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $bookedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $visitorMessage = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $visitorPhone = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isRecurring = false;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $recurringPattern = null; // weekly, biweekly, monthly

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getStartTime(): ?\DateTimeInterface
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeInterface $startTime): static
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): ?\DateTimeInterface
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeInterface $endTime): static
    {
        $this->endTime = $endTime;
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

    public function getBookedBy(): ?User
    {
        return $this->bookedBy;
    }

    public function setBookedBy(?User $bookedBy): static
    {
        $this->bookedBy = $bookedBy;
        return $this;
    }

    public function getBookedAt(): ?\DateTimeInterface
    {
        return $this->bookedAt;
    }

    public function setBookedAt(?\DateTimeInterface $bookedAt): static
    {
        $this->bookedAt = $bookedAt;
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

    public function getVisitorMessage(): ?string
    {
        return $this->visitorMessage;
    }

    public function setVisitorMessage(?string $visitorMessage): static
    {
        $this->visitorMessage = $visitorMessage;
        return $this;
    }

    public function getVisitorPhone(): ?string
    {
        return $this->visitorPhone;
    }

    public function setVisitorPhone(?string $visitorPhone): static
    {
        $this->visitorPhone = $visitorPhone;
        return $this;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function setIsRecurring(bool $isRecurring): static
    {
        $this->isRecurring = $isRecurring;
        return $this;
    }

    public function getRecurringPattern(): ?string
    {
        return $this->recurringPattern;
    }

    public function setRecurringPattern(?string $recurringPattern): static
    {
        $this->recurringPattern = $recurringPattern;
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

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function book(User $user, ?string $message = null, ?string $phone = null): static
    {
        $this->status = self::STATUS_BOOKED;
        $this->bookedBy = $user;
        $this->bookedAt = new \DateTime();
        $this->visitorMessage = $message;
        $this->visitorPhone = $phone;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function cancel(): static
    {
        $this->status = self::STATUS_CANCELLED;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function complete(): static
    {
        $this->status = self::STATUS_COMPLETED;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'listingId' => $this->listing?->getId(),
            'listingTitle' => $this->listing?->getTitle(),
            'ownerId' => $this->owner?->getId(),
            'ownerName' => $this->owner?->getFullName(),
            'date' => $this->date?->format('Y-m-d'),
            'startTime' => $this->startTime?->format('H:i'),
            'endTime' => $this->endTime?->format('H:i'),
            'status' => $this->status,
            'bookedBy' => $this->bookedBy ? [
                'id' => $this->bookedBy->getId(),
                'name' => $this->bookedBy->getFullName(),
                'phone' => $this->visitorPhone,
            ] : null,
            'bookedAt' => $this->bookedAt?->format('c'),
            'notes' => $this->notes,
            'visitorMessage' => $this->visitorMessage,
            'isRecurring' => $this->isRecurring,
            'recurringPattern' => $this->recurringPattern,
            'createdAt' => $this->createdAt?->format('c'),
        ];
    }
}
