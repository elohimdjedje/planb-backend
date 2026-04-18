<?php

namespace App\Entity;

use App\Repository\ReviewStatsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReviewStatsRepository::class)]
#[ORM\Table(name: 'review_stats')]
class ReviewStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private int $totalReviews = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2)]
    private string $averageRating = '0.00';

    #[ORM\Column]
    private int $rating1Count = 0;

    #[ORM\Column]
    private int $rating2Count = 0;

    #[ORM\Column]
    private int $rating3Count = 0;

    #[ORM\Column]
    private int $rating4Count = 0;

    #[ORM\Column]
    private int $rating5Count = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $responseRate = '0.00';

    #[ORM\Column]
    private int $avgResponseTimeHours = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastUpdated = null;

    public function __construct()
    {
        $this->lastUpdated = new \DateTime();
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

    public function getTotalReviews(): int
    {
        return $this->totalReviews;
    }

    public function setTotalReviews(int $totalReviews): static
    {
        $this->totalReviews = $totalReviews;
        return $this;
    }

    public function getAverageRating(): string
    {
        return $this->averageRating;
    }

    public function setAverageRating(string $averageRating): static
    {
        $this->averageRating = $averageRating;
        return $this;
    }

    public function getRating1Count(): int
    {
        return $this->rating1Count;
    }

    public function setRating1Count(int $rating1Count): static
    {
        $this->rating1Count = $rating1Count;
        return $this;
    }

    public function getRating2Count(): int
    {
        return $this->rating2Count;
    }

    public function setRating2Count(int $rating2Count): static
    {
        $this->rating2Count = $rating2Count;
        return $this;
    }

    public function getRating3Count(): int
    {
        return $this->rating3Count;
    }

    public function setRating3Count(int $rating3Count): static
    {
        $this->rating3Count = $rating3Count;
        return $this;
    }

    public function getRating4Count(): int
    {
        return $this->rating4Count;
    }

    public function setRating4Count(int $rating4Count): static
    {
        $this->rating4Count = $rating4Count;
        return $this;
    }

    public function getRating5Count(): int
    {
        return $this->rating5Count;
    }

    public function setRating5Count(int $rating5Count): static
    {
        $this->rating5Count = $rating5Count;
        return $this;
    }

    public function getResponseRate(): string
    {
        return $this->responseRate;
    }

    public function setResponseRate(string $responseRate): static
    {
        $this->responseRate = $responseRate;
        return $this;
    }

    public function getAvgResponseTimeHours(): int
    {
        return $this->avgResponseTimeHours;
    }

    public function setAvgResponseTimeHours(int $avgResponseTimeHours): static
    {
        $this->avgResponseTimeHours = $avgResponseTimeHours;
        return $this;
    }

    public function getLastUpdated(): ?\DateTimeInterface
    {
        return $this->lastUpdated;
    }

    public function setLastUpdated(?\DateTimeInterface $lastUpdated): static
    {
        $this->lastUpdated = $lastUpdated;
        return $this;
    }

    /**
     * Incrémente le compteur pour une note donnée
     */
    public function incrementRatingCount(int $rating): static
    {
        $property = 'rating' . $rating . 'Count';
        if (property_exists($this, $property)) {
            $this->$property++;
        }
        return $this;
    }

    /**
     * Décrémente le compteur pour une note donnée
     */
    public function decrementRatingCount(int $rating): static
    {
        $property = 'rating' . $rating . 'Count';
        if (property_exists($this, $property) && $this->$property > 0) {
            $this->$property--;
        }
        return $this;
    }

    /**
     * Recalcule la note moyenne basée sur les compteurs
     */
    public function recalculateAverageRating(): static
    {
        if ($this->totalReviews === 0) {
            $this->averageRating = '0.00';
            return $this;
        }

        $sum = ($this->rating1Count * 1) +
               ($this->rating2Count * 2) +
               ($this->rating3Count * 3) +
               ($this->rating4Count * 4) +
               ($this->rating5Count * 5);

        $this->averageRating = number_format($sum / $this->totalReviews, 2);
        return $this;
    }
}
