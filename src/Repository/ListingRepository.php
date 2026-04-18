<?php

namespace App\Repository;

use App\Entity\Listing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Listing::class);
    }

    /**
     * Récupérer les annonces actives triées par score du vendeur
     * Score = somme des vues + somme des contacts de toutes les annonces du vendeur
     */
    public function findActiveListings(int $limit = 20, ?string $lastId = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTime());

        if ($lastId) {
            $qb->andWhere('l.id < :lastId')
                ->setParameter('lastId', $lastId);
        }

        $listings = $qb->getQuery()->getResult();
        
        // Calculer le score pour chaque vendeur et trier
        usort($listings, function($a, $b) {
            $scoreA = $this->calculateSellerScore($a->getUser());
            $scoreB = $this->calculateSellerScore($b->getUser());
            
            // Trier par score décroissant
            if ($scoreB !== $scoreA) {
                return $scoreB - $scoreA;
            }
            
            // Si même score, trier par vues de l'annonce
            if ($b->getViewsCount() !== $a->getViewsCount()) {
                return $b->getViewsCount() - $a->getViewsCount();
            }
            
            // Sinon par date de création
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return array_slice($listings, 0, $limit);
    }

    /**
     * Récupérer les annonces récentes (publiées il y a moins d'une semaine)
     * Pour la section "Top Annonces" - toutes les annonces, pas seulement PRO
     */
    public function findRecentListings(int $limit = 20): array
    {
        $threeDaysAgo = new \DateTime('-3 days');
        
        $qb = $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('l.createdAt >= :threeDaysAgo')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTime())
            ->setParameter('threeDaysAgo', $threeDaysAgo)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }
    
    /**
     * Calculer le score d'un vendeur (cache en mémoire pour éviter les calculs répétés)
     */
    private array $sellerScoreCache = [];
    
    private function calculateSellerScore($user): int
    {
        $userId = $user->getId();
        
        if (!isset($this->sellerScoreCache[$userId])) {
            $score = 0;
            foreach ($user->getListings() as $listing) {
                $score += $listing->getViewsCount() + $listing->getContactsCount();
            }
            $this->sellerScoreCache[$userId] = $score;
        }
        
        return $this->sellerScoreCache[$userId];
    }

    /**
     * Rechercher des annonces triées par score du vendeur
     */
    public function searchListings(array $filters, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->setParameter('status', 'active');

        // Recherche par mot-clé dans le titre et la description
        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = trim($filters['search']);

            // Normaliser pour la recherche (minuscules)
            $normalized = mb_strtolower($search, 'UTF-8');

            // Comprendre quelques intentions simples en français
            // Ex: "moto loué", "moto à louer" => type=location, catégorie=vehicule
            if (str_contains($normalized, 'moto')) {
                $filters['category'] = $filters['category'] ?? 'vehicule';
            }
            if (
                str_contains($normalized, 'louer') ||
                str_contains($normalized, 'loué') ||
                str_contains($normalized, 'location')
            ) {
                $filters['type'] = $filters['type'] ?? 'location';
            }

            // Découper en mots-clés pour que "moto loué" trouve aussi "moto à louer"
            $keywords = preg_split('/\s+/', $search);
            $keywordIndex = 0;
            foreach ($keywords as $word) {
                if (mb_strlen($word) < 2) {
                    continue;
                }
                $paramName = 'kw' . $keywordIndex++;
                $qb->andWhere("(LOWER(l.title) LIKE :$paramName OR LOWER(l.description) LIKE :$paramName)")
                   ->setParameter($paramName, '%' . mb_strtolower($word, 'UTF-8') . '%');
            }
        }

        if (isset($filters['category'])) {
            $qb->andWhere('l.category = :category')
                ->setParameter('category', $filters['category']);
        }

        if (isset($filters['subcategory'])) {
            $qb->andWhere('l.subcategory = :subcategory')
                ->setParameter('subcategory', $filters['subcategory']);
        }

        if (isset($filters['type'])) {
            $qb->andWhere('l.type = :type')
                ->setParameter('type', $filters['type']);
        }

        if (isset($filters['country'])) {
            $qb->andWhere('l.country = :country')
                ->setParameter('country', $filters['country']);
        }

        if (isset($filters['city'])) {
            $qb->andWhere('l.city = :city')
                ->setParameter('city', $filters['city']);
        }

        if (isset($filters['commune'])) {
            $qb->andWhere('l.commune = :commune')
                ->setParameter('commune', $filters['commune']);
        }

        if (isset($filters['priceMin'])) {
            $qb->andWhere('l.price >= :priceMin')
                ->setParameter('priceMin', $filters['priceMin']);
        }

        if (isset($filters['priceMax'])) {
            $qb->andWhere('l.price <= :priceMax')
                ->setParameter('priceMax', $filters['priceMax']);
        }

        if (isset($filters['userId'])) {
            $qb->andWhere('l.user = :userId')
                ->setParameter('userId', $filters['userId']);
        }

        $listings = $qb->getQuery()->getResult();
        
        // Trier par score du vendeur
        usort($listings, function($a, $b) {
            $scoreA = $this->calculateSellerScore($a->getUser());
            $scoreB = $this->calculateSellerScore($b->getUser());
            
            if ($scoreB !== $scoreA) {
                return $scoreB - $scoreA;
            }
            
            if ($b->getViewsCount() !== $a->getViewsCount()) {
                return $b->getViewsCount() - $a->getViewsCount();
            }
            
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return array_slice($listings, 0, $limit);
    }

    /**
     * Récupérer les annonces des vendeurs PRO (publiées il y a moins de 7 jours)
     */
    public function findProListings(int $limit = 10): array
    {
        $sevenDaysAgo = new \DateTime('-7 days');
        
        return $this->createQueryBuilder('l')
            ->join('l.user', 'u')
            ->where('l.status = :status')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('u.accountType = :accountType')
            ->andWhere('l.createdAt >= :sevenDaysAgo')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTime())
            ->setParameter('accountType', 'PRO')
            ->setParameter('sevenDaysAgo', $sevenDaysAgo)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
