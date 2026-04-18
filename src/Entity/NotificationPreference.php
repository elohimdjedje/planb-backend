<?php

namespace App\Entity;

use App\Repository\NotificationPreferenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preference')]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private bool $favoritesRemoved = true;

    #[ORM\Column]
    private bool $listingExpired = true;

    #[ORM\Column]
    private bool $subscriptionExpiring = true;

    #[ORM\Column]
    private bool $reviewReceived = true;

    #[ORM\Column]
    private bool $reviewNegativeOnly = false;

    #[ORM\Column]
    private bool $emailEnabled = true;

    #[ORM\Column]
    private bool $pushEnabled = true;

    #[ORM\Column(length: 20)]
    private string $emailFrequency = 'immediate';

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $doNotDisturbStart = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $doNotDisturbEnd = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->emailFrequency = 'immediate';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function isFavoritesRemoved(): bool
    {
        return $this->favoritesRemoved;
    }

    public function setFavoritesRemoved(bool $favoritesRemoved): static
    {
        $this->favoritesRemoved = $favoritesRemoved;
        return $this;
    }

    public function isListingExpired(): bool
    {
        return $this->listingExpired;
    }

    public function setListingExpired(bool $listingExpired): static
    {
        $this->listingExpired = $listingExpired;
        return $this;
    }

    public function isSubscriptionExpiring(): bool
    {
        return $this->subscriptionExpiring;
    }

    public function setSubscriptionExpiring(bool $subscriptionExpiring): static
    {
        $this->subscriptionExpiring = $subscriptionExpiring;
        return $this;
    }

    public function isReviewReceived(): bool
    {
        return $this->reviewReceived;
    }

    public function setReviewReceived(bool $reviewReceived): static
    {
        $this->reviewReceived = $reviewReceived;
        return $this;
    }

    public function isReviewNegativeOnly(): bool
    {
        return $this->reviewNegativeOnly;
    }

    public function setReviewNegativeOnly(bool $reviewNegativeOnly): static
    {
        $this->reviewNegativeOnly = $reviewNegativeOnly;
        return $this;
    }

    public function isEmailEnabled(): bool
    {
        return $this->emailEnabled;
    }

    public function setEmailEnabled(bool $emailEnabled): static
    {
        $this->emailEnabled = $emailEnabled;
        return $this;
    }

    public function isPushEnabled(): bool
    {
        return $this->pushEnabled;
    }

    public function setPushEnabled(bool $pushEnabled): static
    {
        $this->pushEnabled = $pushEnabled;
        return $this;
    }

    public function getEmailFrequency(): string
    {
        return $this->emailFrequency;
    }

    public function setEmailFrequency(string $emailFrequency): static
    {
        $this->emailFrequency = $emailFrequency;
        return $this;
    }

    public function getDoNotDisturbStart(): ?\DateTimeInterface
    {
        return $this->doNotDisturbStart;
    }

    public function setDoNotDisturbStart(?\DateTimeInterface $doNotDisturbStart): static
    {
        $this->doNotDisturbStart = $doNotDisturbStart;
        return $this;
    }

    public function getDoNotDisturbEnd(): ?\DateTimeInterface
    {
        return $this->doNotDisturbEnd;
    }

    public function setDoNotDisturbEnd(?\DateTimeInterface $doNotDisturbEnd): static
    {
        $this->doNotDisturbEnd = $doNotDisturbEnd;
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

    /**
     * Vérifie si nous sommes dans la période "Ne pas déranger"
     */
    public function isInDoNotDisturbPeriod(): bool
    {
        if ($this->doNotDisturbStart === null || $this->doNotDisturbEnd === null) {
            return false;
        }

        $now = new \DateTime();
        $currentTime = $now->format('H:i:s');
        $startTime = $this->doNotDisturbStart->format('H:i:s');
        $endTime = $this->doNotDisturbEnd->format('H:i:s');

        if ($startTime < $endTime) {
            return $currentTime >= $startTime && $currentTime <= $endTime;
        } else {
            // Cas où la période traverse minuit
            return $currentTime >= $startTime || $currentTime <= $endTime;
        }
    }
}
