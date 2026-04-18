<?php

namespace App\Repository;

use App\Entity\AvailabilityCalendar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AvailabilityCalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvailabilityCalendar::class);
    }

    /**
     * Vérifie si une période est disponible
     */
    public function isPeriodAvailable(int $listingId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): bool
    {
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.listing = :listingId')
            ->andWhere('a.date >= :startDate')
            ->andWhere('a.date <= :endDate')
            ->andWhere('(a.isAvailable = false OR a.isBlocked = true)')
            ->setParameter('listingId', $listingId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return $count == 0;
    }

    /**
     * Trouve les dates disponibles d'une annonce
     */
    public function findAvailableDates(int $listingId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.listing = :listingId')
            ->andWhere('a.date >= :startDate')
            ->andWhere('a.date <= :endDate')
            ->andWhere('a.isAvailable = true')
            ->andWhere('a.isBlocked = false')
            ->setParameter('listingId', $listingId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('a.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
