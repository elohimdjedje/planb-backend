<?php

namespace App\Repository;

use App\Entity\UserDocument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDocument>
 */
class UserDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDocument::class);
    }

    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findValidByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt IS NULL OR d.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('status', UserDocument::STATUS_VALIDATED)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    public function findValidByUserAndType(User $user, string $docType): ?UserDocument
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.docType = :docType')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt IS NULL OR d.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('docType', $docType)
            ->setParameter('status', UserDocument::STATUS_VALIDATED)
            ->setParameter('now', new \DateTime())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPendingByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', UserDocument::STATUS_UPLOADED)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function hasValidDocument(User $user, string $docType): bool
    {
        return $this->findValidByUserAndType($user, $docType) !== null;
    }

    public function getValidDocTypes(User $user): array
    {
        $result = $this->createQueryBuilder('d')
            ->select('d.docType')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt IS NULL OR d.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('status', UserDocument::STATUS_VALIDATED)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();

        return array_column($result, 'docType');
    }
}
