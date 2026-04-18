<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Récupérer les messages d'une conversation
     */
    public function findByConversation(Conversation $conversation, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->select('m', 's')
            ->join('m.sender', 's')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les messages non lus pour un utilisateur dans une conversation
     */
    public function countUnreadInConversation(Conversation $conversation, User $user): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.conversation = :conversation')
            ->andWhere('m.isRead = :false')
            ->andWhere('m.sender != :user')
            ->setParameter('conversation', $conversation)
            ->setParameter('false', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marquer tous les messages d'une conversation comme lus
     */
    public function markAllAsRead(Conversation $conversation, User $user): int
    {
        return $this->createQueryBuilder('m')
            ->update()
            ->set('m.isRead', ':true')
            ->set('m.readAt', ':now')
            ->where('m.conversation = :conversation')
            ->andWhere('m.isRead = :false')
            ->andWhere('m.sender != :user')
            ->setParameter('conversation', $conversation)
            ->setParameter('user', $user)
            ->setParameter('true', true)
            ->setParameter('false', false)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Récupérer le total de messages non lus pour un utilisateur
     */
    public function countUnreadForUser(User $user): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.conversation', 'c')
            ->where('(c.buyer = :user OR c.seller = :user)')
            ->andWhere('m.isRead = :false')
            ->andWhere('m.sender != :user')
            ->setParameter('user', $user)
            ->setParameter('false', false)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
