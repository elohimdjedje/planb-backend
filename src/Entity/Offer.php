<?php

namespace App\Entity;

use App\Repository\OfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OfferRepository::class)]
#[ORM\Table(name: 'offers')]
#[ORM\Index(columns: ['listing_id'], name: 'idx_offer_listing')]
#[ORM\Index(columns: ['buyer_id'], name: 'idx_offer_buyer')]
#[ORM\Index(columns: ['status'], name: 'idx_offer_status')]
class Offer
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COUNTER_OFFER = 'counter_offer';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Listing $listing = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $buyer = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $seller = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant de l\'offre est requis')]
    #[Assert\Positive(message: 'Le montant doit être positif')]
    private ?string $amount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $counterOfferAmount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $buyerPhone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sellerResponse = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $respondedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->expiresAt = new \DateTime('+7 days'); // Offre valide 7 jours par défaut
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

    public function getBuyer(): ?User
    {
        return $this->buyer;
    }

    public function setBuyer(?User $buyer): static
    {
        $this->buyer = $buyer;
        return $this;
    }

    public function getSeller(): ?User
    {
        return $this->seller;
    }

    public function setSeller(?User $seller): static
    {
        $this->seller = $seller;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCounterOfferAmount(): ?string
    {
        return $this->counterOfferAmount;
    }

    public function setCounterOfferAmount(?string $counterOfferAmount): static
    {
        $this->counterOfferAmount = $counterOfferAmount;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getBuyerPhone(): ?string
    {
        return $this->buyerPhone;
    }

    public function setBuyerPhone(?string $buyerPhone): static
    {
        $this->buyerPhone = $buyerPhone;
        return $this;
    }

    public function getSellerResponse(): ?string
    {
        return $this->sellerResponse;
    }

    public function setSellerResponse(?string $sellerResponse): static
    {
        $this->sellerResponse = $sellerResponse;
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

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getRespondedAt(): ?\DateTimeInterface
    {
        return $this->respondedAt;
    }

    public function setRespondedAt(?\DateTimeInterface $respondedAt): static
    {
        $this->respondedAt = $respondedAt;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt && $this->expiresAt < new \DateTime();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }

    public function getStatusLabel(): string
    {
        if ($this->isExpired() && $this->status === self::STATUS_PENDING) {
            return 'Expirée';
        }

        return match($this->status) {
            self::STATUS_PENDING => 'En attente',
            self::STATUS_ACCEPTED => 'Acceptée',
            self::STATUS_REJECTED => 'Refusée',
            self::STATUS_COUNTER_OFFER => 'Contre-offre',
            self::STATUS_CANCELLED => 'Annulée',
            self::STATUS_EXPIRED => 'Expirée',
            default => $this->status
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'listingId' => $this->listing?->getId(),
            'listingTitle' => $this->listing?->getTitle(),
            'listingPrice' => $this->listing?->getPrice(),
            'buyerId' => $this->buyer?->getId(),
            'buyerName' => $this->buyer ? $this->buyer->getFirstName() . ' ' . $this->buyer->getLastName() : null,
            'buyerPhone' => $this->buyerPhone,
            'sellerId' => $this->seller?->getId(),
            'sellerName' => $this->seller ? $this->seller->getFirstName() . ' ' . $this->seller->getLastName() : null,
            'amount' => $this->amount,
            'counterOfferAmount' => $this->counterOfferAmount,
            'message' => $this->message,
            'sellerResponse' => $this->sellerResponse,
            'status' => $this->status,
            'statusLabel' => $this->getStatusLabel(),
            'isExpired' => $this->isExpired(),
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
            'expiresAt' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'respondedAt' => $this->respondedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
