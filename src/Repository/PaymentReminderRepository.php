<?php

namespace App\Repository;

use App\Entity\PaymentReminder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PaymentReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentReminder::class);
    }

    /**
     * Trouve les rappels à envoyer maintenant
     */
    public function findDueReminders(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.scheduledAt <= :now')
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTime())
            ->orderBy('r.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
