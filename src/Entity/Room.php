<?php

namespace App\Entity;

use App\Repository\RoomRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'rooms')]
#[ORM\Index(columns: ['listing_id'], name: 'idx_room_listing')]
#[ORM\Index(columns: ['type'], name: 'idx_room_type')]
#[ORM\Index(columns: ['status'], name: 'idx_room_status')]
class Room
{
    public const TYPE_SIMPLE = 'simple';
    public const TYPE_DOUBLE = 'double';
    public const TYPE_SUITE = 'suite';
    public const TYPE_DELUXE = 'deluxe';
    public const TYPE_FAMILIALE = 'familiale';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_MAINTENANCE = 'maintenance';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Listing::class, inversedBy: 'rooms')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Listing $listing = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Le numéro de chambre est requis')]
    private ?string $number = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['simple', 'double', 'suite', 'deluxe', 'familiale'], message: 'Type de chambre invalide')]
    private string $type = self::TYPE_SIMPLE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix par nuit est requis')]
    #[Assert\Positive(message: 'Le prix doit être positif')]
    private ?string $pricePerNight = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Positive(message: 'La capacité doit être positive')]
    private int $capacity = 2;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $beds = 1;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $amenities = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $images = [];

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_AVAILABLE;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'room', targetEntity: Booking::class)]
    private Collection $bookings;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->bookings = new ArrayCollection();
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

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
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

    public function getPricePerNight(): ?string
    {
        return $this->pricePerNight;
    }

    public function setPricePerNight(string $pricePerNight): static
    {
        $this->pricePerNight = $pricePerNight;
        return $this;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): static
    {
        $this->capacity = $capacity;
        return $this;
    }

    public function getBeds(): ?int
    {
        return $this->beds;
    }

    public function setBeds(?int $beds): static
    {
        $this->beds = $beds;
        return $this;
    }

    public function getAmenities(): ?array
    {
        return $this->amenities;
    }

    public function setAmenities(?array $amenities): static
    {
        $this->amenities = $amenities;
        return $this;
    }

    public function getImages(): ?array
    {
        return $this->images;
    }

    public function setImages(?array $images): static
    {
        $this->images = $images;
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

    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    public function addBooking(Booking $booking): static
    {
        if (!$this->bookings->contains($booking)) {
            $this->bookings->add($booking);
            $booking->setRoom($this);
        }
        return $this;
    }

    public function removeBooking(Booking $booking): static
    {
        if ($this->bookings->removeElement($booking)) {
            if ($booking->getRoom() === $this) {
                $booking->setRoom(null);
            }
        }
        return $this;
    }

    public function getTypeLabel(): string
    {
        return match($this->type) {
            self::TYPE_SIMPLE => 'Chambre Simple',
            self::TYPE_DOUBLE => 'Chambre Double',
            self::TYPE_SUITE => 'Suite',
            self::TYPE_DELUXE => 'Chambre Deluxe',
            self::TYPE_FAMILIALE => 'Chambre Familiale',
            default => $this->type
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'listingId' => $this->listing?->getId(),
            'number' => $this->number,
            'type' => $this->type,
            'typeLabel' => $this->getTypeLabel(),
            'name' => $this->name,
            'description' => $this->description,
            'pricePerNight' => $this->pricePerNight,
            'capacity' => $this->capacity,
            'beds' => $this->beds,
            'amenities' => $this->amenities,
            'images' => $this->images,
            'status' => $this->status,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
