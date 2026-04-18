<?php

namespace App\Repository;

use App\Entity\Room;
use App\Entity\Listing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /**
     * Trouve toutes les chambres d'un listing
     */
    public function findByListing(Listing $listing): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.listing = :listing')
            ->setParameter('listing', $listing)
            ->orderBy('r.type', 'ASC')
            ->addOrderBy('r.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les chambres par type pour un listing
     */
    public function findByListingAndType(Listing $listing, string $type): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.listing = :listing')
            ->andWhere('r.type = :type')
            ->setParameter('listing', $listing)
            ->setParameter('type', $type)
            ->orderBy('r.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les chambres disponibles pour une période donnée
     */
    public function findAvailableRooms(Listing $listing, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.listing = :listing')
            ->andWhere('r.status = :status')
            ->setParameter('listing', $listing)
            ->setParameter('status', Room::STATUS_AVAILABLE);

        // Exclure les chambres qui ont des réservations chevauchant la période
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(b.room)')
            ->from('App\Entity\Booking', 'b')
            ->where('b.room IS NOT NULL')
            ->andWhere('b.status IN (:activeStatuses)')
            ->andWhere('(b.startDate <= :endDate AND b.endDate >= :startDate)')
            ->getDQL();

        $qb->andWhere($qb->expr()->notIn('r.id', $subQuery))
            ->setParameter('activeStatuses', ['pending', 'accepted', 'confirmed', 'active'])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        return $qb->orderBy('r.type', 'ASC')
            ->addOrderBy('r.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si une chambre est disponible pour une période
     */
    public function isRoomAvailable(Room $room, \DateTimeInterface $startDate, \DateTimeInterface $endDate, ?int $excludeBookingId = null): bool
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from('App\Entity\Booking', 'b')
            ->where('b.room = :room')
            ->andWhere('b.status IN (:activeStatuses)')
            ->andWhere('(b.startDate <= :endDate AND b.endDate >= :startDate)')
            ->setParameter('room', $room)
            ->setParameter('activeStatuses', ['pending', 'accepted', 'confirmed', 'active'])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if ($excludeBookingId) {
            $qb->andWhere('b.id != :excludeId')
                ->setParameter('excludeId', $excludeBookingId);
        }

        return (int)$qb->getQuery()->getSingleScalarResult() === 0;
    }

    /**
     * Obtient les types de chambres disponibles pour un listing
     */
    public function getRoomTypes(Listing $listing): array
    {
        return $this->createQueryBuilder('r')
            ->select('DISTINCT r.type')
            ->andWhere('r.listing = :listing')
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Compte les chambres par type pour un listing
     */
    public function countByType(Listing $listing): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('r.type, COUNT(r.id) as count')
            ->andWhere('r.listing = :listing')
            ->setParameter('listing', $listing)
            ->groupBy('r.type')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['type']] = $result['count'];
        }
        return $counts;
    }

    /**
     * Obtient le calendrier de disponibilité d'une chambre
     */
    public function getRoomCalendar(Room $room, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $bookings = $this->getEntityManager()->createQueryBuilder()
            ->select('b.startDate, b.endDate, b.status')
            ->from('App\Entity\Booking', 'b')
            ->where('b.room = :room')
            ->andWhere('b.status IN (:activeStatuses)')
            ->andWhere('(b.startDate <= :endDate AND b.endDate >= :startDate)')
            ->setParameter('room', $room)
            ->setParameter('activeStatuses', ['pending', 'accepted', 'confirmed', 'active'])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getResult();

        $calendar = [];
        $current = clone $startDate;
        
        while ($current <= $endDate) {
            $dateStr = $current->format('Y-m-d');
            $calendar[$dateStr] = [
                'date' => $dateStr,
                'available' => true,
                'status' => null
            ];

            foreach ($bookings as $booking) {
                if ($current >= $booking['startDate'] && $current <= $booking['endDate']) {
                    $calendar[$dateStr]['available'] = false;
                    $calendar[$dateStr]['status'] = $booking['status'];
                    break;
                }
            }

            $current = (clone $current)->modify('+1 day');
        }

        return $calendar;
    }
}
