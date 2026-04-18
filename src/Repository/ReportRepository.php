<?php

namespace App\Repository;

use App\Entity\Report;
use App\Entity\Listing;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    /**
     * Récupérer tous les signalements en attente
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'l', 'u')
            ->join('r.listing', 'l')
            ->leftJoin('r.reporter', 'u')
            ->where('r.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les signalements d'une annonce
     */
    public function countByListing(Listing $listing): int
    {
        return $this->count(['listing' => $listing]);
    }

    /**
     * Vérifier si un utilisateur a déjà signalé une annonce
     */
    public function hasUserReportedListing(User $user, Listing $listing): bool
    {
        return $this->count([
            'reporter' => $user,
            'listing' => $listing
        ]) > 0;
    }

    /**
     * Récupérer les signalements par statut
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'l', 'u')
            ->join('r.listing', 'l')
            ->leftJoin('r.reporter', 'u')
            ->where('r.status = :status')
            ->setParameter('status', $status)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
