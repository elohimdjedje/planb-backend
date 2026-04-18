<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use App\Entity\Listing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Récupérer toutes les conversations d'un utilisateur
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->select('c', 'l', 'b', 's', 'm')
            ->join('c.listing', 'l')
            ->join('c.buyer', 'b')
            ->join('c.seller', 's')
            ->leftJoin('c.messages', 'm')
            ->where('c.buyer = :user OR c.seller = :user')
            ->setParameter('user', $user)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver une conversation spécifique
     */
    public function findByListingAndBuyer(Listing $listing, User $buyer): ?Conversation
    {
        return $this->findOneBy([
            'listing' => $listing,
            'buyer' => $buyer
        ]);
    }

    /**
     * Créer ou récupérer une conversation
     */
    public function findOrCreate(Listing $listing, User $buyer): Conversation
    {
        $conversation = $this->findByListingAndBuyer($listing, $buyer);
        
        if ($conversation) {
            return $conversation;
        }

        $conversation = new Conversation();
        $conversation->setListing($listing);
        $conversation->setBuyer($buyer);
        $conversation->setSeller($listing->getUser());

        $this->getEntityManager()->persist($conversation);
        $this->getEntityManager()->flush();

        return $conversation;
    }

    /**
     * Compter les conversations non lues pour un utilisateur
     */
    public function countUnreadConversations(User $user): int
    {
        $qb = $this->createQueryBuilder('c');
        
        return $qb
            ->select('COUNT(DISTINCT c.id)')
            ->join('c.messages', 'm')
            ->where('(c.buyer = :user OR c.seller = :user)')
            ->andWhere('m.isRead = :false')
            ->andWhere('m.sender != :user')
            ->setParameter('user', $user)
            ->setParameter('false', false)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
