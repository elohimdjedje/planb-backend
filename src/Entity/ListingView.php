<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entité pour tracker les vues d'annonces
 */
#[ORM\Entity(repositoryClass: \App\Repository\ListingViewRepository::class)]
#[ORM\Table(name: 'listing_views')]
#[ORM\Index(name: 'idx_listing_viewed_at', columns: ['listing_id', 'viewed_at'])]
#[ORM\Index(name: 'idx_user_ip', columns: ['user_id', 'ip_address'])]
#[ORM\Index(name: 'idx_fingerprint', columns: ['listing_id', 'fingerprint'])]
#[ORM\UniqueConstraint(name: 'unique_view', columns: ['listing_id', 'user_id', 'fingerprint'])]
class ListingView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Listing $listing;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(type: 'string', length: 45)]
    private string $ipAddress;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $referrer = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $viewedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getListing(): Listing
    {
        return $this->listing;
    }

    public function setListing(Listing $listing): self
    {
        $this->listing = $listing;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(?string $referrer): self
    {
        $this->referrer = $referrer;
        return $this;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;
        return $this;
    }

    public function getViewedAt(): \DateTime
    {
        return $this->viewedAt;
    }

    public function setViewedAt(\DateTime $viewedAt): self
    {
        $this->viewedAt = $viewedAt;
        return $this;
    }
}
