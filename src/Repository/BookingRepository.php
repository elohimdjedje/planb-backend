<?php

namespace App\Repository;

use App\Entity\Booking;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Trouve les réservations d'un utilisateur (propriétaire ou locataire)
     */
    public function findByUser(int $userId, ?string $role = null): array
    {
        $qb = $this->createQueryBuilder('b');

        if ($role === 'owner') {
            $qb->where('b.owner = :userId');
        } elseif ($role === 'tenant') {
            $qb->where('b.tenant = :userId');
        } else {
            $qb->where('b.owner = :userId OR b.tenant = :userId');
        }

        return $qb->setParameter('userId', $userId)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les réservations actives d'une annonce
     */
    public function findActiveByListing(int $listingId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.listing = :listingId')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('listingId', $listingId)
            ->setParameter('statuses', ['confirmed', 'active'])
            ->orderBy('b.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si une période est disponible pour une annonce
     */
    public function isPeriodAvailable(int $listingId, \DateTimeInterface $startDate, \DateTimeInterface $endDate, ?int $excludeBookingId = null): bool
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.listing = :listingId')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('(b.startDate <= :endDate AND b.endDate >= :startDate)')
            ->setParameter('listingId', $listingId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('statuses', ['pending', 'accepted', 'confirmed', 'active']);

        if ($excludeBookingId) {
            $qb->andWhere('b.id != :excludeId')
                ->setParameter('excludeId', $excludeBookingId);
        }

        $count = $qb->getQuery()->getSingleScalarResult();
        return $count == 0;
    }

    /**
     * Vérifie si un utilisateur a une réservation active ou terminée pour une annonce
     * (utilisé pour valider qu'un avis est légitime)
     */
    public function hasCompletedBookingForListing(\App\Entity\User $user, \App\Entity\Listing $listing): bool
    {
        $count = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.listing = :listing')
            ->andWhere('b.tenant = :user')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('listing', $listing)
            ->setParameter('user', $user)
            ->setParameter('statuses', ['active', 'completed'])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Trouve les réservations en retard de paiement
     */
    public function findOverdueBookings(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.depositPaid = false OR b.firstRentPaid = false')
            ->setParameter('status', 'confirmed')
            ->getQuery()
            ->getResult();
    }
}
