<?php

namespace App\Repository;

use App\Entity\ReviewStats;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReviewStats>
 */
class ReviewStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReviewStats::class);
    }

    /**
     * Récupère ou crée les statistiques d'un utilisateur
     */
    public function findOrCreateForUser(User $user): ReviewStats
    {
        $stats = $this->findOneBy(['user' => $user]);

        if (!$stats) {
            $stats = new ReviewStats();
            $stats->setUser($user);
            $this->getEntityManager()->persist($stats);
            $this->getEntityManager()->flush();
        }

        return $stats;
    }

    /**
     * Récupère les vendeurs avec les meilleures notes
     */
    public function findTopRated(int $limit = 10): array
    {
        return $this->createQueryBuilder('rs')
            ->where('rs.totalReviews >= :minReviews')
            ->setParameter('minReviews', 5)
            ->orderBy('rs.averageRating', 'DESC')
            ->addOrderBy('rs.totalReviews', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
