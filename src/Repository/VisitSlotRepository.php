<?php

namespace App\Repository;

use App\Entity\Listing;
use App\Entity\User;
use App\Entity\VisitSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour gérer les créneaux de visite
 */
class VisitSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VisitSlot::class);
    }

    /**
     * Trouver les créneaux disponibles pour une annonce
     */
    public function findAvailableForListing(Listing $listing, ?\DateTime $fromDate = null): array
    {
        $fromDate = $fromDate ?? new \DateTime('today');
        
        return $this->createQueryBuilder('v')
            ->where('v.listing = :listing')
            ->andWhere('v.status = :status')
            ->andWhere('v.date >= :fromDate')
            ->setParameter('listing', $listing)
            ->setParameter('status', VisitSlot::STATUS_AVAILABLE)
            ->setParameter('fromDate', $fromDate)
            ->orderBy('v.date', 'ASC')
            ->addOrderBy('v.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver tous les créneaux d'un propriétaire
     */
    public function findByOwner(User $owner, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->where('v.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('v.date', 'DESC')
            ->addOrderBy('v.startTime', 'ASC');

        if ($status) {
            $qb->andWhere('v.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouver les créneaux réservés par un utilisateur
     */
    public function findBookedByUser(User $user): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.bookedBy = :user')
            ->andWhere('v.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [VisitSlot::STATUS_BOOKED, VisitSlot::STATUS_COMPLETED])
            ->orderBy('v.date', 'DESC')
            ->addOrderBy('v.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les créneaux à venir pour un propriétaire
     */
    public function findUpcomingForOwner(User $owner, int $limit = 10): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.owner = :owner')
            ->andWhere('v.date >= :today')
            ->andWhere('v.status IN (:statuses)')
            ->setParameter('owner', $owner)
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('statuses', [VisitSlot::STATUS_AVAILABLE, VisitSlot::STATUS_BOOKED])
            ->orderBy('v.date', 'ASC')
            ->addOrderBy('v.startTime', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifier si un créneau existe déjà (pour éviter les doublons)
     */
    public function slotExists(Listing $listing, \DateTime $date, \DateTime $startTime, \DateTime $endTime, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.listing = :listing')
            ->andWhere('v.date = :date')
            ->andWhere('v.startTime = :startTime')
            ->andWhere('v.endTime = :endTime')
            ->andWhere('v.status != :cancelled')
            ->setParameter('listing', $listing)
            ->setParameter('date', $date)
            ->setParameter('startTime', $startTime)
            ->setParameter('endTime', $endTime)
            ->setParameter('cancelled', VisitSlot::STATUS_CANCELLED);

        if ($excludeId) {
            $qb->andWhere('v.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Trouver les créneaux par annonce avec pagination
     */
    public function findByListingPaginated(Listing $listing, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $slots = $this->createQueryBuilder('v')
            ->where('v.listing = :listing')
            ->setParameter('listing', $listing)
            ->orderBy('v.date', 'ASC')
            ->addOrderBy('v.startTime', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $total = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.listing = :listing')
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'slots' => $slots,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
        ];
    }

    /**
     * Compter les créneaux par statut pour un propriétaire
     */
    public function countByStatusForOwner(User $owner): array
    {
        $result = $this->createQueryBuilder('v')
            ->select('v.status, COUNT(v.id) as count')
            ->where('v.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('v.status')
            ->getQuery()
            ->getResult();

        $counts = [
            'available' => 0,
            'booked' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => 0,
        ];

        foreach ($result as $row) {
            $counts[$row['status']] = (int) $row['count'];
            $counts['total'] += (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Générer des créneaux récurrents
     */
    public function generateRecurringSlots(VisitSlot $template, int $weeks = 4): array
    {
        $slots = [];
        $date = clone $template->getDate();

        for ($i = 1; $i <= $weeks; $i++) {
            switch ($template->getRecurringPattern()) {
                case 'weekly':
                    $date->modify('+1 week');
                    break;
                case 'biweekly':
                    $date->modify('+2 weeks');
                    break;
                case 'monthly':
                    $date->modify('+1 month');
                    break;
                default:
                    continue 2;
            }

            // Vérifier que le créneau n'existe pas déjà
            if (!$this->slotExists(
                $template->getListing(),
                clone $date,
                $template->getStartTime(),
                $template->getEndTime()
            )) {
                $slot = new VisitSlot();
                $slot->setListing($template->getListing());
                $slot->setOwner($template->getOwner());
                $slot->setDate(clone $date);
                $slot->setStartTime($template->getStartTime());
                $slot->setEndTime($template->getEndTime());
                $slot->setNotes($template->getNotes());
                $slot->setIsRecurring(true);
                $slot->setRecurringPattern($template->getRecurringPattern());

                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Auto-expire les créneaux dont la date OU l'heure est passée :
     * - "booked"    → "completed"
     * - "available" → "cancelled"
     *
     * Cas 1 : date strictement passée (hier ou avant)
     * Cas 2 : date = aujourd'hui ET endTime <= heure actuelle
     */
    public function expirePastSlots(): int
    {
        $today   = new \DateTime('today');
        $now     = new \DateTime();
        $nowTime = new \DateTime($now->format('H:i:s'));

        // ── Booked → Completed ──
        // Cas 1 : date passée
        $bookedPastDay = $this->getEntityManager()->createQueryBuilder()
            ->update(VisitSlot::class, 'v')
            ->set('v.status', ':completed')
            ->set('v.updatedAt', ':now')
            ->where('v.date < :today')
            ->andWhere('v.status = :booked')
            ->setParameter('completed', VisitSlot::STATUS_COMPLETED)
            ->setParameter('booked', VisitSlot::STATUS_BOOKED)
            ->setParameter('today', $today)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        // Cas 2 : aujourd'hui + heure de fin passée
        $bookedPastTime = $this->getEntityManager()->createQueryBuilder()
            ->update(VisitSlot::class, 'v')
            ->set('v.status', ':completed')
            ->set('v.updatedAt', ':now')
            ->where('v.date = :today')
            ->andWhere('v.endTime <= :nowTime')
            ->andWhere('v.status = :booked')
            ->setParameter('completed', VisitSlot::STATUS_COMPLETED)
            ->setParameter('booked', VisitSlot::STATUS_BOOKED)
            ->setParameter('today', $today)
            ->setParameter('nowTime', $nowTime)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        // ── Available → Cancelled ──
        // Cas 1 : date passée
        $availablePastDay = $this->getEntityManager()->createQueryBuilder()
            ->update(VisitSlot::class, 'v')
            ->set('v.status', ':cancelled')
            ->set('v.updatedAt', ':now')
            ->where('v.date < :today')
            ->andWhere('v.status = :available')
            ->setParameter('cancelled', VisitSlot::STATUS_CANCELLED)
            ->setParameter('available', VisitSlot::STATUS_AVAILABLE)
            ->setParameter('today', $today)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        // Cas 2 : aujourd'hui + heure de fin passée
        $availablePastTime = $this->getEntityManager()->createQueryBuilder()
            ->update(VisitSlot::class, 'v')
            ->set('v.status', ':cancelled')
            ->set('v.updatedAt', ':now')
            ->where('v.date = :today')
            ->andWhere('v.endTime <= :nowTime')
            ->andWhere('v.status = :available')
            ->setParameter('cancelled', VisitSlot::STATUS_CANCELLED)
            ->setParameter('available', VisitSlot::STATUS_AVAILABLE)
            ->setParameter('today', $today)
            ->setParameter('nowTime', $nowTime)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        return $bookedPastDay + $bookedPastTime + $availablePastDay + $availablePastTime;
    }
}
