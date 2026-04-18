<?php

namespace App\Repository;

use App\Entity\ModerationAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModerationAction>
 */
class ModerationActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationAction::class);
    }

    /**
     * Récupérer les actions récentes
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->select('m', 'moderator', 'report')
            ->leftJoin('m.moderator', 'moderator')
            ->leftJoin('m.relatedReport', 'report')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupérer les actions par type
     */
    public function findByActionType(string $actionType, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->select('m', 'moderator')
            ->leftJoin('m.moderator', 'moderator')
            ->where('m.actionType = :actionType')
            ->setParameter('actionType', $actionType)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiques de modération
     */
    public function getModerationStats(): array
    {
        $qb = $this->createQueryBuilder('m');
        
        $stats = [
            'total' => (int) $qb->select('COUNT(m.id)')->getQuery()->getSingleScalarResult(),
            'byAction' => [],
            'byTarget' => [],
            'last30Days' => 0
        ];

        // Par type d'action
        $byAction = $this->createQueryBuilder('m')
            ->select('m.actionType', 'COUNT(m.id) as count')
            ->groupBy('m.actionType')
            ->getQuery()
            ->getResult();

        foreach ($byAction as $row) {
            $stats['byAction'][$row['actionType']] = (int) $row['count'];
        }

        // Par type de cible
        $byTarget = $this->createQueryBuilder('m')
            ->select('m.targetType', 'COUNT(m.id) as count')
            ->groupBy('m.targetType')
            ->getQuery()
            ->getResult();

        foreach ($byTarget as $row) {
            $stats['byTarget'][$row['targetType']] = (int) $row['count'];
        }

        // Derniers 30 jours
        $last30Days = new \DateTime();
        $last30Days->modify('-30 days');
        
        $stats['last30Days'] = (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.createdAt >= :date')
            ->setParameter('date', $last30Days)
            ->getQuery()
            ->getSingleScalarResult();

        return $stats;
    }
}


