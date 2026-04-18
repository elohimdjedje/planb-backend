<?php

namespace App\Repository;

use App\Entity\Favorite;
use App\Entity\User;
use App\Entity\Listing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Favorite>
 */
class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    /**
     * Trouver un favori spécifique
     */
    public function findByUserAndListing(User $user, Listing $listing): ?Favorite
    {
        return $this->findOneBy([
            'user' => $user,
            'listing' => $listing
        ]);
    }

    /**
     * Récupérer tous les favoris d'un utilisateur avec les annonces
     */
    public function findByUserWithListings(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->select('f', 'l', 'u', 'i')
            ->join('f.listing', 'l')
            ->join('l.user', 'u')
            ->leftJoin('l.images', 'i')
            ->where('f.user = :user')
            ->andWhere('l.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'active')
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les favoris d'un utilisateur
     */
    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * Vérifier si une annonce est en favori
     */
    public function isFavorite(User $user, Listing $listing): bool
    {
        return $this->findByUserAndListing($user, $listing) !== null;
    }
}
