<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Récupère les notifications d'un utilisateur
     *
     * @param User $user
     * @param string|null $status
     * @param int $limit
     * @return Notification[]
     */
    public function findByUser(User $user, ?string $status = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('n.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les notifications non lues d'un utilisateur
     */
    public function countUnread(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'unread')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marque toutes les notifications d'un utilisateur comme lues
     */
    public function markAllAsRead(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.status', ':status')
            ->set('n.readAt', ':readAt')
            ->where('n.user = :user')
            ->andWhere('n.status = :unread')
            ->setParameter('status', 'read')
            ->setParameter('readAt', new \DateTime())
            ->setParameter('user', $user)
            ->setParameter('unread', 'unread')
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les notifications expirées
     */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.expiresAt IS NOT NULL')
            ->andWhere('n.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les anciennes notifications lues (plus de 30 jours)
     */
    public function deleteOldRead(int $days = 30): int
    {
        $date = new \DateTime();
        $date->modify("-{$days} days");

        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.status = :status')
            ->andWhere('n.readAt < :date')
            ->setParameter('status', 'read')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }

    /**
     * Récupère les notifications par type pour un utilisateur
     */
    public function findByType(User $user, string $type, int $limit = 10): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
