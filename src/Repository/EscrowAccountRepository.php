<?php

namespace App\Repository;

use App\Entity\EscrowAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EscrowAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscrowAccount::class);
    }

    /**
     * Trouve les comptes séquestres prêts à être libérés
     */
    public function findReadyForRelease(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.depositReleaseDate <= :now')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }
}
