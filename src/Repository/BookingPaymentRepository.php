<?php

namespace App\Repository;

use App\Entity\BookingPayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BookingPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingPayment::class);
    }

    /**
     * Trouve les paiements en retard
     */
    public function findOverduePayments(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status != :completed')
            ->andWhere('p.dueDate < :now')
            ->setParameter('completed', 'completed')
            ->setParameter('now', new \DateTime())
            ->orderBy('p.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les paiements d'une réservation
     */
    public function findByBooking(int $bookingId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.booking = :bookingId')
            ->setParameter('bookingId', $bookingId)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les paiements d'un utilisateur
     */
    public function findByUser(int $userId, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.user = :userId')
            ->setParameter('userId', $userId);

        if ($type) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }

        return $qb->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
