<?php

namespace App\Repository;

use App\Entity\SecurityLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityLog>
 */
class SecurityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityLog::class);
    }

    /**
     * Récupérer les logs d'un utilisateur
     */
    public function findByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('sl')
            ->where('sl.user = :user')
            ->setParameter('user', $user)
            ->orderBy('sl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupérer les logs par action
     */
    public function findByAction(string $action, int $limit = 100): array
    {
        return $this->createQueryBuilder('sl')
            ->where('sl.action = :action')
            ->setParameter('action', $action)
            ->orderBy('sl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupérer les logs critiques récents
     */
    public function findCriticalLogs(int $limit = 50): array
    {
        return $this->createQueryBuilder('sl')
            ->where('sl.severity = :severity')
            ->setParameter('severity', 'critical')
            ->orderBy('sl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les tentatives de connexion échouées pour une IP
     */
    public function countFailedLoginsByIp(string $ipAddress, \DateTimeImmutable $since): int
    {
        return $this->createQueryBuilder('sl')
            ->select('COUNT(sl.id)')
            ->where('sl.action = :action')
            ->andWhere('sl.ipAddress = :ip')
            ->andWhere('sl.createdAt >= :since')
            ->setParameter('action', 'failed_login')
            ->setParameter('ip', $ipAddress)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprimer les logs anciens (plus de 90 jours)
     */
    public function deleteOldLogs(int $days = 90): int
    {
        $date = new \DateTimeImmutable("-{$days} days");
        
        return $this->createQueryBuilder('sl')
            ->delete()
            ->where('sl.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}
