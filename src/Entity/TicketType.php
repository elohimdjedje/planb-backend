<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'ticket_types')]
#[ORM\Index(columns: ['status'], name: 'idx_ticket_type_status')]
class TicketType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'ticketTypes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Event $event = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom du billet est requis')]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Le prix est requis')]
    #[Assert\Positive(message: 'Le prix doit être positif')]
    private ?string $price = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'La quantité est requise')]
    #[Assert\PositiveOrZero(message: 'La quantité doit être positive ou nulle')]
    private int $quantity = 0;

    #[ORM\Column]
    private int $sold = 0;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['available', 'sold_out', 'hidden'], message: 'Statut invalide')]
    private string $status = 'available';

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
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

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
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

    public function getSold(): int
    {
        return $this->sold;
    }

    public function setSold(int $sold): static
    {
        $this->sold = $sold;
        return $this;
    }

    public function incrementSold(int $quantity): static
    {
        $this->sold += $quantity;
        
        // Auto-update status if sold out
        if ($this->sold >= $this->quantity) {
            $this->status = 'sold_out';
        }
        
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getAvailableQuantity(): int
    {
        return max(0, $this->quantity - $this->sold);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available' && $this->getAvailableQuantity() > 0;
    }
}
