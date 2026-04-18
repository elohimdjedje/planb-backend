<?php

namespace App\Repository;

use App\Entity\Listing;
use App\Entity\ListingView;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour gérer les vues d'annonces
 * Optimisé pour éviter les doublons et la fraude
 */
class ListingViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListingView::class);
    }

    /**
     * Vérifie si une vue existe déjà pour cette combinaison listing + user/fingerprint
     */
    public function hasAlreadyViewed(Listing $listing, ?int $userId, string $fingerprint): bool
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.listing = :listing')
            ->setParameter('listing', $listing);

        // Si utilisateur connecté, vérifier par userId
        if ($userId !== null) {
            $qb->andWhere('v.userId = :userId')
               ->setParameter('userId', $userId);
        } else {
            // Sinon, vérifier par fingerprint
            $qb->andWhere('v.fingerprint = :fingerprint')
               ->setParameter('fingerprint', $fingerprint);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Enregistre une nouvelle vue si elle n'existe pas déjà
     * Retourne true si la vue a été comptée, false sinon
     */
    public function recordView(
        Listing $listing,
        ?int $userId,
        string $ipAddress,
        string $fingerprint,
        ?string $userAgent = null,
        ?string $referrer = null
    ): bool {
        // Vérifier si déjà vue
        if ($this->hasAlreadyViewed($listing, $userId, $fingerprint)) {
            return false;
        }

        // Créer la nouvelle vue
        $view = new ListingView();
        $view->setListing($listing);
        $view->setUserId($userId);
        $view->setIpAddress($ipAddress);
        $view->setFingerprint($fingerprint);
        $view->setUserAgent($userAgent);
        $view->setReferrer($referrer);
        $view->setViewedAt(new \DateTime());

        try {
            $this->getEntityManager()->persist($view);
            $this->getEntityManager()->flush();
            
            // Incrémenter le compteur de vues dans l'annonce
            $listing->setViewsCount($listing->getViewsCount() + 1);
            $this->getEntityManager()->flush();
            
            return true;
        } catch (\Exception $e) {
            // En cas de contrainte unique violée (race condition), ignorer
            return false;
        }
    }

    /**
     * Obtenir les statistiques de vues pour une annonce
     */
    public function getViewStats(Listing $listing): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select([
                'COUNT(v.id) as totalViews',
                'COUNT(DISTINCT v.userId) as uniqueUsers',
                'COUNT(DISTINCT v.ipAddress) as uniqueIps',
            ])
            ->where('v.listing = :listing')
            ->setParameter('listing', $listing);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Nettoyer les anciennes vues (optionnel, pour maintenance)
     */
    public function cleanOldViews(int $daysToKeep = 90): int
    {
        $threshold = new \DateTime("-{$daysToKeep} days");

        return $this->createQueryBuilder('v')
            ->delete()
            ->where('v.viewedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
