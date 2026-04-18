<?php

namespace App\Repository;

use App\Entity\Offer;
use App\Entity\SaleContract;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaleContract>
 */
class SaleContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaleContract::class);
    }

    public function findByOffer(Offer $offer): ?SaleContract
    {
        return $this->findOneBy(['offer' => $offer]);
    }

    public function findByBuyer(User $buyer): array
    {
        return $this->createQueryBuilder('sc')
            ->andWhere('sc.buyer = :buyer')
            ->setParameter('buyer', $buyer)
            ->orderBy('sc.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySeller(User $seller): array
    {
        return $this->createQueryBuilder('sc')
            ->andWhere('sc.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('sc.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('sc')
            ->andWhere('sc.buyer = :user OR sc.seller = :user')
            ->setParameter('user', $user)
            ->orderBy('sc.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
