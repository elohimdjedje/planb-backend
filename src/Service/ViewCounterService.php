<?php

namespace App\Service;

use App\Entity\Listing;
use App\Entity\ListingView;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service pour gérer le comptage unique des vues d'annonces
 * 1 utilisateur = 1 vue, même s'il regarde plusieurs fois
 */
class ViewCounterService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack
    ) {
    }

    /**
     * Enregistrer une vue pour une annonce
     * Retourne true si c'est une nouvelle vue unique, false sinon
     */
    public function recordView(Listing $listing, ?User $user = null): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return false;
        }

        $userId = $user?->getId();
        $ipAddress = $this->getClientIp($request);

        // Vérifier si cette combinaison utilisateur/IP a déjà vu cette annonce
        $existingView = $this->entityManager
            ->getRepository(ListingView::class)
            ->createQueryBuilder('lv')
            ->where('lv.listing = :listing')
            ->andWhere('lv.userId = :userId OR lv.ipAddress = :ip')
            ->setParameter('listing', $listing)
            ->setParameter('userId', $userId)
            ->setParameter('ip', $ipAddress)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        // Si la vue existe déjà, ne pas compter
        if ($existingView) {
            return false;
        }

        // Créer une nouvelle vue
        $view = new ListingView();
        $view->setListing($listing)
            ->setUserId($userId)
            ->setIpAddress($ipAddress)
            ->setUserAgent($request->headers->get('User-Agent'))
            ->setReferrer($request->headers->get('Referer'))
            ->setViewedAt(new \DateTime());

        $this->entityManager->persist($view);
        
        // Incrémenter le compteur de vues de l'annonce
        $listing->setViewsCount($listing->getViewsCount() + 1);
        
        $this->entityManager->flush();

        return true;
    }

    /**
     * Obtenir le nombre de vues uniques pour une annonce
     */
    public function getUniqueViewsCount(Listing $listing): int
    {
        return (int) $this->entityManager
            ->getRepository(ListingView::class)
            ->createQueryBuilder('lv')
            ->select('COUNT(DISTINCT CASE WHEN lv.userId IS NOT NULL THEN lv.userId ELSE lv.ipAddress END)')
            ->where('lv.listing = :listing')
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifier si un utilisateur a déjà vu une annonce
     */
    public function hasUserViewed(Listing $listing, ?User $user = null): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return false;
        }

        $userId = $user?->getId();
        $ipAddress = $this->getClientIp($request);

        $count = $this->entityManager
            ->getRepository(ListingView::class)
            ->createQueryBuilder('lv')
            ->select('COUNT(lv.id)')
            ->where('lv.listing = :listing')
            ->andWhere('lv.userId = :userId OR lv.ipAddress = :ip')
            ->setParameter('listing', $listing)
            ->setParameter('userId', $userId)
            ->setParameter('ip', $ipAddress)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Nettoyer les anciennes vues (> 90 jours)
     */
    public function cleanOldViews(): int
    {
        $date = new \DateTime('-90 days');
        
        return $this->entityManager
            ->createQuery('DELETE FROM App\Entity\ListingView lv WHERE lv.viewedAt < :date')
            ->setParameter('date', $date)
            ->execute();
    }

    /**
     * Obtenir l'IP du client en tenant compte des proxies
     */
    private function getClientIp(Request $request): string
    {
        $ip = $request->getClientIp();
        
        // Anonymiser l'IP pour la conformité RGPD (garder seulement les 3 premiers octets)
        $ipParts = explode('.', $ip);
        if (count($ipParts) === 4) {
            $ipParts[3] = '0'; // Anonymiser le dernier octet
            return implode('.', $ipParts);
        }
        
        return $ip;
    }
}
