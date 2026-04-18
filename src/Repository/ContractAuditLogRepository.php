<?php

namespace App\Repository;

use App\Entity\ContractAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContractAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractAuditLog::class);
    }

    public function findByContractId(int $contractId): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('IDENTITY(l.contract) = :id')
            ->setParameter('id', $contractId)
            ->orderBy('l.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
