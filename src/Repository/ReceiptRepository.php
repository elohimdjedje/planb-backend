<?php

namespace App\Repository;

use App\Entity\Receipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Receipt::class);
    }

    /**
     * Trouve les quittances d'une réservation
     */
    public function findByBooking(int $bookingId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.booking = :bookingId')
            ->setParameter('bookingId', $bookingId)
            ->orderBy('r.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve une quittance par son numéro
     */
    public function findByReceiptNumber(string $receiptNumber): ?Receipt
    {
        return $this->createQueryBuilder('r')
            ->where('r.receiptNumber = :number')
            ->setParameter('number', $receiptNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
