<?php

namespace App\Repository;

use App\Entity\DepositDispute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DepositDispute>
 */
class DepositDisputeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepositDispute::class);
    }

    public function findPendingByDeposit(int $depositId): array
    {
        return $this->createQueryBuilder('dd')
            ->where('dd.secureDeposit = :did')
            ->andWhere('dd.status = :status')
            ->setParameter('did', $depositId)
            ->setParameter('status', DepositDispute::STATUS_PENDING)
            ->getQuery()
            ->getResult();
    }
}
