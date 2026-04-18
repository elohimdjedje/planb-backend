<?php

namespace App\Entity;

use App\Repository\AvailabilityCalendarRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AvailabilityCalendarRepository::class)]
#[ORM\Table(name: 'availability_calendar')]
#[ORM\UniqueConstraint(name: 'unique_listing_date', columns: ['listing_id', 'date'])]
#[ORM\Index(columns: ['listing_id'], name: 'idx_calendar_listing')]
#[ORM\Index(columns: ['date'], name: 'idx_calendar_date')]
#[ORM\Index(columns: ['listing_id', 'date', 'is_available'], name: 'idx_calendar_available')]
class AvailabilityCalendar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Listing $listing = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date est requise')]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isAvailable = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $isBlocked = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $priceOverride = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $blockReason = null;

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

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function setIsAvailable(bool $isAvailable): static
    {
        $this->isAvailable = $isAvailable;
        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function setIsBlocked(bool $isBlocked): static
    {
        $this->isBlocked = $isBlocked;
        if ($isBlocked) {
            $this->isAvailable = false;
        }
        return $this;
    }

    public function getPriceOverride(): ?string
    {
        return $this->priceOverride;
    }

    public function setPriceOverride(?string $priceOverride): static
    {
        $this->priceOverride = $priceOverride;
        return $this;
    }

    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }

    public function setBlockReason(?string $blockReason): static
    {
        $this->blockReason = $blockReason;
        return $this;
    }

    /**
     * Vérifie si la date est disponible pour réservation
     */
    public function canBeBooked(): bool
    {
        return $this->isAvailable && !$this->isBlocked;
    }
}
