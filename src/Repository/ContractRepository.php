<?php

namespace App\Repository;

use App\Entity\Contract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    /**
     * Trouve les contrats en attente de signature par le locataire (statut draft)
     */
    public function findPendingTenantSignature(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', 'draft')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les contrats signés par le locataire, en attente du propriétaire
     */
    public function findPendingOwnerSignature(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', 'tenant_signed')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les contrats verrouillés mais non payés
     */
    public function findLockedUnpaid(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.paymentStatus IS NULL OR c.paymentStatus = :pending')
            ->setParameter('status', 'locked')
            ->setParameter('pending', 'payment_pending')
            ->getQuery()
            ->getResult();
    }

    /**
     * @deprecated Utilisez findPendingTenantSignature() à la place
     */
    public function findPendingSignatures(): array
    {
        return $this->findPendingTenantSignature();
    }
}
