<?php

namespace App\Repository;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Trouver les commandes par statut
     */
    public function findByStatus(bool $status): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :status')
            ->setParameter('status', $status)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les commandes d'un client
     */
    public function findByClient(int $clientId): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.client = :clientId')
            ->setParameter('clientId', $clientId)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les commandes d'un prestataire
     */
    public function findByProvider(int $providerId): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.provider = :providerId')
            ->setParameter('providerId', $providerId)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver une commande par Wave Session ID
     */
    public function findByWaveSessionId(string $sessionId): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.waveSessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouver une commande par Orange Money Transaction ID
     */
    public function findByOmTransactionId(string $transactionId): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.omTransactionId = :transactionId')
            ->setParameter('transactionId', $transactionId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
