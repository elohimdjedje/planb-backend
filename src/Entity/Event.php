<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\Index(columns: ['status'], name: 'idx_event_status')]
#[ORM\Index(columns: ['category'], name: 'idx_event_category')]
#[ORM\Index(columns: ['country', 'city'], name: 'idx_event_location')]
#[ORM\Index(columns: ['event_date'], name: 'idx_event_date')]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
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
        min: 50,
        max: 5000,
        minMessage: 'La description doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères'
    )]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'La catégorie est requise')]
    #[Assert\Choice(choices: ['concert', 'festival', 'conference', 'theatre', 'sport'], message: 'Catégorie invalide')]
    private ?string $category = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de l\'événement est requise')]
    private ?\DateTimeInterface $eventDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $eventEndDate = null;

    #[ORM\Column(length: 2)]
    #[Assert\NotBlank(message: 'Le pays est requis')]
    #[Assert\Choice(choices: ['CI', 'BJ', 'SN', 'ML'], message: 'Pays non supporté')]
    private ?string $country = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville est requise')]
    private ?string $city = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'L\'adresse est requise')]
    private ?string $address = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $specifications = [];

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['draft', 'active', 'completed', 'cancelled'], message: 'Statut invalide')]
    private string $status = 'draft';

    #[ORM\Column]
    private int $viewsCount = 0;

    #[ORM\Column]
    private int $totalTicketsSold = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, EventImage>
     */
    #[ORM\OneToMany(targetEntity: EventImage::class, mappedBy: 'event', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['orderPosition' => 'ASC'])]
    private Collection $images;

    /**
     * @var Collection<int, EventVideo>
     */
    #[ORM\OneToMany(targetEntity: EventVideo::class, mappedBy: 'event', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $videos;

    /**
     * @var Collection<int, TicketType>
     */
    #[ORM\OneToMany(targetEntity: TicketType::class, mappedBy: 'event', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $ticketTypes;

    public function __construct()
    {
        $this->images = new ArrayCollection();
        $this->videos = new ArrayCollection();
        $this->ticketTypes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getEventDate(): ?\DateTimeInterface
    {
        return $this->eventDate;
    }

    public function setEventDate(\DateTimeInterface $eventDate): static
    {
        $this->eventDate = $eventDate;
        return $this;
    }

    public function getEventEndDate(): ?\DateTimeInterface
    {
        return $this->eventEndDate;
    }

    public function setEventEndDate(?\DateTimeInterface $eventEndDate): static
    {
        $this->eventEndDate = $eventEndDate;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getTotalTicketsSold(): int
    {
        return $this->totalTicketsSold;
    }

    public function setTotalTicketsSold(int $totalTicketsSold): static
    {
        $this->totalTicketsSold = $totalTicketsSold;
        return $this;
    }

    public function incrementTicketsSold(int $quantity): static
    {
        $this->totalTicketsSold += $quantity;
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

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->eventDate > new \DateTime();
    }

    public function isCompleted(): bool
    {
        return $this->eventDate < new \DateTime();
    }

    /**
     * @return Collection<int, EventImage>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(EventImage $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setEvent($this);
        }
        return $this;
    }

    public function removeImage(EventImage $image): static
    {
        if ($this->images->removeElement($image)) {
            if ($image->getEvent() === $this) {
                $image->setEvent(null);
            }
        }
        return $this;
    }

    public function getMainImage(): ?EventImage
    {
        return $this->images->first() ?: null;
    }

    /**
     * @return Collection<int, EventVideo>
     */
    public function getVideos(): Collection
    {
        return $this->videos;
    }

    public function addVideo(EventVideo $video): static
    {
        if (!$this->videos->contains($video)) {
            $this->videos->add($video);
            $video->setEvent($this);
        }
        return $this;
    }

    public function removeVideo(EventVideo $video): static
    {
        if ($this->videos->removeElement($video)) {
            if ($video->getEvent() === $this) {
                $video->setEvent(null);
            }
        }
        return $this;
    }

    public function getMainVideo(): ?EventVideo
    {
        return $this->videos->first() ?: null;
    }

    /**
     * @return Collection<int, TicketType>
     */
    public function getTicketTypes(): Collection
    {
        return $this->ticketTypes;
    }

    public function addTicketType(TicketType $ticketType): static
    {
        if (!$this->ticketTypes->contains($ticketType)) {
            $this->ticketTypes->add($ticketType);
            $ticketType->setEvent($this);
        }
        return $this;
    }

    public function removeTicketType(TicketType $ticketType): static
    {
        if ($this->ticketTypes->removeElement($ticketType)) {
            if ($ticketType->getEvent() === $this) {
                $ticketType->setEvent(null);
            }
        }
        return $this;
    }

    public function getMinTicketPrice(): ?float
    {
        $prices = [];
        foreach ($this->ticketTypes as $ticketType) {
            if ($ticketType->getStatus() === 'available') {
                $prices[] = (float) $ticketType->getPrice();
            }
        }
        return empty($prices) ? null : min($prices);
    }
}
