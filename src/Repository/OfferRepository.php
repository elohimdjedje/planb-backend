<?php

namespace App\Repository;

use App\Entity\Offer;
use App\Entity\Listing;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Offer>
 */
class OfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Offer::class);
    }

    /**
     * Offres reçues par un vendeur
     */
    public function findBySeller(User $seller, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('o.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('o.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Offres envoyées par un acheteur
     */
    public function findByBuyer(User $buyer, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.buyer = :buyer')
            ->setParameter('buyer', $buyer)
            ->orderBy('o.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('o.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Offres pour un listing
     */
    public function findByListing(Listing $listing, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.listing = :listing')
            ->setParameter('listing', $listing)
            ->orderBy('o.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('o.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Vérifie si un utilisateur a déjà une offre en attente sur un listing
     */
    public function hasPendingOffer(User $buyer, Listing $listing): bool
    {
        $count = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.buyer = :buyer')
            ->andWhere('o.listing = :listing')
            ->andWhere('o.status = :status')
            ->andWhere('o.expiresAt > :now')
            ->setParameter('buyer', $buyer)
            ->setParameter('listing', $listing)
            ->setParameter('status', Offer::STATUS_PENDING)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Vérifie si le listing a une offre acceptée
     */
    public function hasAcceptedOffer(Listing $listing): bool
    {
        $count = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.listing = :listing')
            ->andWhere('o.status = :status')
            ->setParameter('listing', $listing)
            ->setParameter('status', Offer::STATUS_ACCEPTED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Meilleure offre pour un listing
     */
    public function getBestOffer(Listing $listing): ?Offer
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.listing = :listing')
            ->andWhere('o.status = :status')
            ->andWhere('o.expiresAt > :now')
            ->setParameter('listing', $listing)
            ->setParameter('status', Offer::STATUS_PENDING)
            ->setParameter('now', new \DateTime())
            ->orderBy('o.amount', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte les offres en attente pour un listing
     */
    public function countPendingOffers(Listing $listing): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.listing = :listing')
            ->andWhere('o.status = :status')
            ->andWhere('o.expiresAt > :now')
            ->setParameter('listing', $listing)
            ->setParameter('status', Offer::STATUS_PENDING)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Expire les offres dont la date est dépassée
     */
    public function expireOldOffers(): int
    {
        return $this->createQueryBuilder('o')
            ->update()
            ->set('o.status', ':expiredStatus')
            ->where('o.status = :pendingStatus')
            ->andWhere('o.expiresAt < :now')
            ->setParameter('expiredStatus', Offer::STATUS_EXPIRED)
            ->setParameter('pendingStatus', Offer::STATUS_PENDING)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }

    /**
     * Statistiques des offres pour un vendeur
     */
    public function getSellerStats(User $seller): array
    {
        $results = $this->createQueryBuilder('o')
            ->select('o.status, COUNT(o.id) as count')
            ->andWhere('o.seller = :seller')
            ->setParameter('seller', $seller)
            ->groupBy('o.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'pending' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'counter_offer' => 0,
            'total' => 0
        ];

        foreach ($results as $result) {
            $stats[$result['status']] = $result['count'];
            $stats['total'] += $result['count'];
        }

        return $stats;
    }
}
