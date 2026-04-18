<?php

namespace App\Repository;

use App\Entity\SecureDeposit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecureDeposit>
 */
class SecureDepositRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecureDeposit::class);
    }

    /**
     * Dépôts dont le délai de 72h est expiré sans litige → remboursement automatique.
     */
    public function findReadyForAutoRefund72h(): array
    {
        return $this->createQueryBuilder('sd')
            ->where('sd.status = :status')
            ->andWhere('sd.deadline72hAt IS NOT NULL')
            ->andWhere('sd.deadline72hAt <= :now')
            ->setParameter('status', SecureDeposit::STATUS_END_OF_RENTAL)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    /**
     * Dépôts en litige dont le délai de 7j est expiré sans accord → remboursement automatique.
     */
    public function findReadyForAutoRefund7j(): array
    {
        return $this->createQueryBuilder('sd')
            ->where('sd.status = :status')
            ->andWhere('sd.deadline7jAt IS NOT NULL')
            ->andWhere('sd.deadline7jAt <= :now')
            ->setParameter('status', SecureDeposit::STATUS_DISPUTE_OPEN)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les dépôts d'un utilisateur (en tant que locataire ou bailleur).
     */
    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('sd')
            ->leftJoin('sd.listing', 'l')
            ->leftJoin('sd.tenant', 't')
            ->leftJoin('sd.landlord', 'o')
            ->addSelect('l', 't', 'o')
            ->where('sd.tenant = :uid OR sd.landlord = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('sd.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Dépôts d'une annonce spécifique.
     */
    public function findByListing(int $listingId): array
    {
        return $this->createQueryBuilder('sd')
            ->where('sd.listing = :lid')
            ->setParameter('lid', $listingId)
            ->orderBy('sd.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiques globales pour l'admin.
     */
    public function getAdminStats(): array
    {
        $qb = $this->createQueryBuilder('sd');

        $total = (clone $qb)->select('COUNT(sd.id)')->getQuery()->getSingleScalarResult();
        $active = (clone $qb)->select('COUNT(sd.id)')->where('sd.status = :s')
            ->setParameter('s', SecureDeposit::STATUS_ACTIVE)->getQuery()->getSingleScalarResult();
        $disputed = (clone $qb)->select('COUNT(sd.id)')->where('sd.status = :s')
            ->setParameter('s', SecureDeposit::STATUS_DISPUTE_OPEN)->getQuery()->getSingleScalarResult();
        $totalVolume = (clone $qb)->select('SUM(sd.depositAmount)')->getQuery()->getSingleScalarResult();
        $totalCommission = (clone $qb)->select('SUM(sd.commissionAmount)')
            ->where('sd.status NOT IN (:excluded)')
            ->setParameter('excluded', [SecureDeposit::STATUS_PENDING_PAYMENT, SecureDeposit::STATUS_CANCELLED])
            ->getQuery()->getSingleScalarResult();

        return [
            'total_deposits'    => (int) $total,
            'active_deposits'   => (int) $active,
            'open_disputes'     => (int) $disputed,
            'total_volume_xof'  => (float) ($totalVolume ?? 0),
            'total_commission'  => (float) ($totalCommission ?? 0),
        ];
    }
}
