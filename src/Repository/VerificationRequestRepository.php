<?php

namespace App\Repository;

use App\Entity\VerificationRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VerificationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VerificationRequest::class);
    }

    /**
     * Trouver les demandes en attente
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.status = :status')
            ->setParameter('status', VerificationRequest::STATUS_PENDING)
            ->orderBy('v.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver la dernière demande d'un utilisateur
     */
    public function findLatestByUser(User $user): ?VerificationRequest
    {
        return $this->createQueryBuilder('v')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->orderBy('v.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compter les tentatives d'un utilisateur
     */
    public function countAttemptsByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouver une demande en attente pour un utilisateur
     */
    public function findPendingByUser(User $user): ?VerificationRequest
    {
        return $this->createQueryBuilder('v')
            ->where('v.user = :user')
            ->andWhere('v.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', VerificationRequest::STATUS_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Stats pour le dashboard admin
     */
    public function getStats(): array
    {
        $qb = $this->createQueryBuilder('v');
        
        $pending = (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.status = :pending')
            ->setParameter('pending', VerificationRequest::STATUS_PENDING)
            ->getQuery()->getSingleScalarResult();

        $approved = (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.status = :approved')
            ->setParameter('approved', VerificationRequest::STATUS_APPROVED)
            ->getQuery()->getSingleScalarResult();

        $rejected = (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.status = :rejected')
            ->setParameter('rejected', VerificationRequest::STATUS_REJECTED)
            ->getQuery()->getSingleScalarResult();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
        ];
    }
}
