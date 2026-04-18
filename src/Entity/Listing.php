<?php

namespace App\Entity;

use App\Repository\ListingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ListingRepository::class)]
#[ORM\Table(name: 'listings')]
#[ORM\Index(columns: ['status'], name: 'idx_listing_status')]
#[ORM\Index(columns: ['category'], name: 'idx_listing_category')]
#[ORM\Index(columns: ['country', 'city'], name: 'idx_listing_location')]
#[ORM\Index(columns: ['created_at'], name: 'idx_listing_created')]
class Listing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'listings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le titre est requis')]
    #[Assert\Length(
        min: 10,
        max: 100,
        minMessage: 'Le titre doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères'
    )]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La description est requise')]
    #[Assert\Length(
        min: 20,
        max: 1000,
        minMessage: 'La description doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères'
    )]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix est requis')]
    #[Assert\Positive(message: 'Le prix doit être positif')]
    private ?string $price = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: ['fixe', 'mois', 'jour', 'heure', 'nuit'], message: 'Unité de prix invalide')]
    private ?string $priceUnit = 'mois';

    #[ORM\Column(length: 3)]
    #[Assert\Choice(choices: ['XOF', 'EUR', 'USD'], message: 'Devise non supportée')]
    private string $currency = 'XOF';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'La catégorie est requise')]
    #[Assert\Choice(choices: ['immobilier', 'vehicule', 'vacance'], message: 'Catégorie invalide')]
    private ?string $category = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $subcategory = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['vente', 'location', 'recherche'], message: 'Type invalide')]
    private string $type = 'vente';

    #[ORM\Column(length: 2)]
    #[Assert\NotBlank(message: 'Le pays est requis')]
    #[Assert\Choice(choices: ['CI', 'BJ', 'SN', 'ML', 'BF', 'GN'], message: 'Pays non supporté')]
    private ?string $country = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville est requise')]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $commune = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $quartier = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['draft', 'active', 'expired', 'sold', 'suspended'], message: 'Statut invalide')]
    private string $status = 'draft';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $specifications = [];

    #[ORM\Column]
    private int $viewsCount = 0;

    #[ORM\Column]
    private int $contactsCount = 0;

    #[ORM\Column]
    private bool $isFeatured = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $contactWhatsapp = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'Email invalide')]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $virtualTourType = null; // '360_photo', '360_video', 'matterport'

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $virtualTourUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $virtualTourThumbnail = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $virtualTourData = null; // Métadonnées (hotspots, annotations, etc.)

    // ── Caution sécurisée (escrow) ──────────────────────────
    #[ORM\Column(options: ['default' => false])]
    private bool $secureDepositEnabled = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $depositAmountRequired = null;

    /**
     * @var Collection<int, Image>
     */
    #[ORM\OneToMany(targetEntity: Image::class, mappedBy: 'listing', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['orderPosition' => 'ASC'])]
    private Collection $images;

    /**
     * @var Collection<int, Room>
     */
    #[ORM\OneToMany(targetEntity: Room::class, mappedBy: 'listing', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $rooms;

    public function __construct()
    {
        $this->images = new ArrayCollection();
        $this->rooms = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->expiresAt = new \DateTime('+30 days'); // Par défaut 30 jours
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getPriceUnit(): ?string
    {
        return $this->priceUnit;
    }

    public function setPriceUnit(?string $priceUnit): static
    {
        $this->priceUnit = $priceUnit;
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getSubcategory(): ?string
    {
        return $this->subcategory;
    }

    public function setSubcategory(?string $subcategory): static
    {
        $this->subcategory = $subcategory;
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

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getCommune(): ?string
    {
        return $this->commune;
    }

    public function setCommune(?string $commune): static
    {
        $this->commune = $commune;
        return $this;
    }

    public function getQuartier(): ?string
    {
        return $this->quartier;
    }

    public function setQuartier(?string $quartier): static
    {
        $this->quartier = $quartier;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;
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

    public function getSpecifications(): ?array
    {
        return $this->specifications;
    }

    public function setSpecifications(?array $specifications): static
    {
        $this->specifications = $specifications;
        return $this;
    }

    public function getViewsCount(): int
    {
        return $this->viewsCount;
    }

    public function setViewsCount(int $viewsCount): static
    {
        $this->viewsCount = $viewsCount;
        return $this;
    }

    public function incrementViews(): static
    {
        $this->viewsCount++;
        return $this;
    }

    public function getContactsCount(): int
    {
        return $this->contactsCount;
    }

    public function setContactsCount(int $contactsCount): static
    {
        $this->contactsCount = $contactsCount;
        return $this;
    }

    public function incrementContacts(): static
    {
        $this->contactsCount++;
        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function setIsFeatured(bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;
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

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTime();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }

    /**
     * @return Collection<int, Image>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(Image $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setListing($this);
        }

        return $this;
    }

    public function removeImage(Image $image): static
    {
        if ($this->images->removeElement($image)) {
            if ($image->getListing() === $this) {
                $image->setListing(null);
            }
        }

        return $this;
    }

    public function getMainImage(): ?Image
    {
        return $this->images->first() ?: null;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): static
    {
        $this->contactPhone = $contactPhone;
        return $this;
    }

    public function getContactWhatsapp(): ?string
    {
        return $this->contactWhatsapp;
    }

    public function setContactWhatsapp(?string $contactWhatsapp): static
    {
        $this->contactWhatsapp = $contactWhatsapp;
        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;
        return $this;
    }

    public function getVirtualTourType(): ?string
    {
        return $this->virtualTourType;
    }

    public function setVirtualTourType(?string $virtualTourType): static
    {
        $this->virtualTourType = $virtualTourType;
        return $this;
    }

    public function getVirtualTourUrl(): ?string
    {
        return $this->virtualTourUrl;
    }

    public function setVirtualTourUrl(?string $virtualTourUrl): static
    {
        $this->virtualTourUrl = $virtualTourUrl;
        return $this;
    }

    public function getVirtualTourThumbnail(): ?string
    {
        return $this->virtualTourThumbnail;
    }

    public function setVirtualTourThumbnail(?string $virtualTourThumbnail): static
    {
        $this->virtualTourThumbnail = $virtualTourThumbnail;
        return $this;
    }

    public function getVirtualTourData(): ?array
    {
        return $this->virtualTourData;
    }

    public function setVirtualTourData(?array $virtualTourData): static
    {
        $this->virtualTourData = $virtualTourData;
        return $this;
    }

    public function hasVirtualTour(): bool
    {
        return $this->virtualTourType !== null && $this->virtualTourUrl !== null;
    }

    /**
     * @return Collection<int, Room>
     */
    public function getRooms(): Collection
    {
        return $this->rooms;
    }

    public function addRoom(Room $room): static
    {
        if (!$this->rooms->contains($room)) {
            $this->rooms->add($room);
            $room->setListing($this);
        }
        return $this;
    }

    public function removeRoom(Room $room): static
    {
        if ($this->rooms->removeElement($room)) {
            if ($room->getListing() === $this) {
                $room->setListing(null);
            }
        }
        return $this;
    }

    public function isHotel(): bool
    {
        return $this->subcategory === 'hotel';
    }

    public function requiresRoomSelection(): bool
    {
        return $this->isHotel() && $this->rooms->count() > 0;
    }

    // ── Caution sécurisée ───────────────────────────────────

    public function isSecureDepositEnabled(): bool
    {
        return $this->secureDepositEnabled;
    }

    public function setSecureDepositEnabled(bool $v): static
    {
        $this->secureDepositEnabled = $v;
        return $this;
    }

    public function getDepositAmountRequired(): ?string
    {
        return $this->depositAmountRequired;
    }

    public function setDepositAmountRequired(?string $v): static
    {
        $this->depositAmountRequired = $v;
        return $this;
    }
}
