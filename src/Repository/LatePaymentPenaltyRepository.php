<?php

namespace App\Repository;

use App\Entity\LatePaymentPenalty;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LatePaymentPenaltyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LatePaymentPenalty::class);
    }

    /**
     * Trouve les pénalités non payées d'une réservation
     */
    public function findUnpaidByBooking(int $bookingId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.booking = :bookingId')
            ->andWhere('p.status = :status')
            ->setParameter('bookingId', $bookingId)
            ->setParameter('status', 'pending')
            ->orderBy('p.calculatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
