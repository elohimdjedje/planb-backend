<?php

namespace App\Repository;

use App\Entity\ScopeVerification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScopeVerification>
 */
class ScopeVerificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScopeVerification::class);
    }

    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('sv')
            ->andWhere('sv.user = :user')
            ->setParameter('user', $user)
            ->orderBy('sv.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndScope(User $user, string $scopeKey): ?ScopeVerification
    {
        return $this->createQueryBuilder('sv')
            ->andWhere('sv.user = :user')
            ->andWhere('sv.scopeKey = :scopeKey')
            ->setParameter('user', $user)
            ->setParameter('scopeKey', $scopeKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findApprovedByUser(User $user): array
    {
        return $this->createQueryBuilder('sv')
            ->andWhere('sv.user = :user')
            ->andWhere('sv.status = :status')
            ->andWhere('sv.expiresAt IS NULL OR sv.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('status', ScopeVerification::STATUS_APPROVED)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    public function findPendingByUser(User $user): array
    {
        return $this->createQueryBuilder('sv')
            ->andWhere('sv.user = :user')
            ->andWhere('sv.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ScopeVerification::STATUS_PENDING)
            ->getQuery()
            ->getResult();
    }

    public function isApprovedForScope(User $user, string $scopeKey): bool
    {
        $verification = $this->findByUserAndScope($user, $scopeKey);
        return $verification !== null && $verification->isApproved();
    }

    public function findAllPending(): array
    {
        return $this->createQueryBuilder('sv')
            ->andWhere('sv.status = :status')
            ->setParameter('status', ScopeVerification::STATUS_PENDING)
            ->orderBy('sv.submittedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return $this->createQueryBuilder('sv')
            ->select('COUNT(sv.id)')
            ->andWhere('sv.status = :status')
            ->setParameter('status', ScopeVerification::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getApprovedScopes(User $user): array
    {
        $result = $this->createQueryBuilder('sv')
            ->select('sv.scopeKey')
            ->andWhere('sv.user = :user')
            ->andWhere('sv.status = :status')
            ->andWhere('sv.expiresAt IS NULL OR sv.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('status', ScopeVerification::STATUS_APPROVED)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();

        return array_column($result, 'scopeKey');
    }

    public function getVerificationStats(): array
    {
        $stats = $this->createQueryBuilder('sv')
            ->select('sv.status, COUNT(sv.id) as count')
            ->groupBy('sv.status')
            ->getQuery()
            ->getResult();

        $result = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'blocked' => 0,
        ];

        foreach ($stats as $stat) {
            $key = strtolower($stat['status']);
            if (isset($result[$key])) {
                $result[$key] = (int) $stat['count'];
            }
        }

        return $result;
    }
}
