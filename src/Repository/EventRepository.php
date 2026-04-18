<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * Find active events with pagination
     */
    public function findActiveEvents(int $page = 1, int $limit = 20, ?array $filters = []): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.eventDate > :now')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTime())
            ->orderBy('e.eventDate', 'ASC');

        // Apply filters
        if (isset($filters['category'])) {
            $qb->andWhere('e.category = :category')
               ->setParameter('category', $filters['category']);
        }

        if (isset($filters['city'])) {
            $qb->andWhere('e.city = :city')
               ->setParameter('city', $filters['city']);
        }

        if (isset($filters['country'])) {
            $qb->andWhere('e.country = :country')
               ->setParameter('country', $filters['country']);
        }

        $qb->setFirstResult(($page - 1) * $limit)
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Count active events
     */
    public function countActiveEvents(?array $filters = []): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.status = :status')
            ->andWhere('e.eventDate > :now')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTime());

        // Apply filters
        if (isset($filters['category'])) {
            $qb->andWhere('e.category = :category')
               ->setParameter('category', $filters['category']);
        }

        if (isset($filters['city'])) {
            $qb->andWhere('e.city = :city')
               ->setParameter('city', $filters['city']);
        }

        if (isset($filters['country'])) {
            $qb->andWhere('e.country = :country')
               ->setParameter('country', $filters['country']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
