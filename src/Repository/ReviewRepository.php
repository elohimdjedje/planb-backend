<?php

namespace App\Repository;

use App\Entity\Review;
use App\Entity\User;
use App\Entity\Listing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * Obtenir la note moyenne d'un vendeur
     */
    public function getAverageRatingForSeller(User $seller): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) as avgRating')
            ->where('r.seller = :seller')
            ->setParameter('seller', $seller)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $result, 1);
    }

    /**
     * Obtenir le nombre total d'avis pour un vendeur
     */
    public function getTotalReviewsForSeller(User $seller): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.seller = :seller')
            ->setParameter('seller', $seller)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Obtenir les avis d'un vendeur avec pagination
     */
    public function getReviewsForSeller(User $seller, int $page = 1, int $limit = 10): array
    {
        $offset = ($page - 1) * $limit;

        return $this->createQueryBuilder('r')
            ->where('r.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Obtenir les avis d'une annonce
     */
    public function getReviewsForListing(Listing $listing): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.listing = :listing')
            ->setParameter('listing', $listing)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obtenir la note moyenne d'une annonce spécifique
     */
    public function getAverageRatingForListing(Listing $listing): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) as avgRating')
            ->where('r.listing = :listing')
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $result, 1);
    }

    /**
     * Obtenir le nombre total d'avis pour une annonce spécifique
     */
    public function getTotalReviewsForListing(Listing $listing): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.listing = :listing')
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifier si un utilisateur a déjà laissé un avis pour une annonce
     */
    public function hasUserReviewedListing(User $reviewer, Listing $listing): bool
    {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.reviewer = :reviewer')
            ->andWhere('r.listing = :listing')
            ->setParameter('reviewer', $reviewer)
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Obtenir la distribution des notes pour un vendeur
     */
    public function getRatingDistributionForSeller(User $seller): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('r.rating, COUNT(r.id) as count')
            ->where('r.seller = :seller')
            ->setParameter('seller', $seller)
            ->groupBy('r.rating')
            ->orderBy('r.rating', 'DESC')
            ->getQuery()
            ->getResult();

        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($results as $result) {
            $distribution[$result['rating']] = (int) $result['count'];
        }

        return $distribution;
    }
}
