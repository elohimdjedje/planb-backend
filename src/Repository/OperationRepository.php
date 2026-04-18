<?php

namespace App\Repository;

use App\Entity\Operation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Operation>
 */
class OperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Operation::class);
    }

    /**
     * Trouver les opérations d'un utilisateur
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->orWhere('o.provider = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculer le solde d'un utilisateur
     */
    public function calculateBalance(User $user): float
    {
        // Somme des entrées
        $in = $this->createQueryBuilder('o')
            ->select('SUM(o.amount)')
            ->where('o.user = :user')
            ->andWhere('o.sens = :in')
            ->setParameter('user', $user)
            ->setParameter('in', 'in')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Somme des sorties
        $out = $this->createQueryBuilder('o')
            ->select('SUM(o.amount)')
            ->where('o.user = :user')
            ->andWhere('o.sens = :out')
            ->setParameter('user', $user)
            ->setParameter('out', 'out')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        return (float)$in - (float)$out;
    }

    /**
     * Obtenir les dernières opérations
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
