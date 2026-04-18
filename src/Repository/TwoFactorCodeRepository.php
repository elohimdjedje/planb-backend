<?php

namespace App\Repository;

use App\Entity\TwoFactorCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TwoFactorCode>
 */
class TwoFactorCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TwoFactorCode::class);
    }

    /**
     * Récupérer le dernier code 2FA non-expiré pour un utilisateur
     */
    public function findLatestValidForUser(User $user): ?TwoFactorCode
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.expiresAt > :now')
            ->andWhere('t.attempts < 5')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTime())
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Supprimer tous les codes 2FA d'un utilisateur
     */
    public function deleteAllForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprimer les codes expirés (maintenance)
     */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :now')
            ->setParameter('now', new \DateTime('-1 hour'))
            ->getQuery()
            ->execute();
    }
}
