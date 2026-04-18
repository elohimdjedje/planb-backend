<?php

namespace App\Controller;

use App\Entity\Favorite;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\ListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/favorites')]
class FavoriteController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FavoriteRepository $favoriteRepository,
        private ListingRepository $listingRepository
    ) {
    }

    #[Route('', name: 'favorites_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $favorites = $this->favoriteRepository->findByUserWithListings($user);

        $data = array_map(function (Favorite $favorite) {
            $listing = $favorite->getListing();
            $images = $listing->getImages()->toArray();
            
            return [
                'id' => $favorite->getId(),
                'listing' => [
                    'id' => $listing->getId(),
                    'title' => $listing->getTitle(),
                    'price' => (float) $listing->getPrice(),
                    'currency' => $listing->getCurrency(),
                    'category' => $listing->getCategory(),
                    'city' => $listing->getCity(),
                    'country' => $listing->getCountry(),
                    'status' => $listing->getStatus(),
                    'mainImage' => !empty($images) ? $images[0]->getUrl() : null,
                    'createdAt' => $listing->getCreatedAt()?->format('c'),
                    'seller' => [
                        'id' => $listing->getUser()->getId(),
                        'fullName' => $listing->getUser()->getFullName(),
                        'isPro' => $listing->getUser()->isPro()
                    ]
                ],
                'createdAt' => $favorite->getCreatedAt()?->format('c')
            ];
        }, $favorites);

        return $this->json([
            'favorites' => $data,
            'total' => count($data)
        ]);
    }

    #[Route('/{listingId}', name: 'favorites_add', methods: ['POST'])]
    public function add(int $listingId): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $listing = $this->listingRepository->find($listingId);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $existing = $this->favoriteRepository->findByUserAndListing($user, $listing);
        
        if ($existing) {
            return $this->json([
                'message' => 'Déjà dans vos favoris',
                'favoriteId' => $existing->getId()
            ], Response::HTTP_OK);
        }

        $favorite = new Favorite();
        $favorite->setUser($user);
        $favorite->setListing($listing);

        $this->entityManager->persist($favorite);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Ajouté aux favoris',
            'favoriteId' => $favorite->getId()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{listingId}', name: 'favorites_remove', methods: ['DELETE'])]
    public function remove(int $listingId): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $listing = $this->listingRepository->find($listingId);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $favorite = $this->favoriteRepository->findByUserAndListing($user, $listing);
        
        if (!$favorite) {
            return $this->json(['error' => 'Favori introuvable'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($favorite);
        $this->entityManager->flush();

        return $this->json(['message' => 'Retiré des favoris'], Response::HTTP_OK);
    }

    #[Route('/check/{listingId}', name: 'favorites_check', methods: ['GET'])]
    public function check(int $listingId): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['isFavorite' => false]);
        }

        $listing = $this->listingRepository->find($listingId);
        
        if (!$listing) {
            return $this->json(['isFavorite' => false]);
        }

        $isFavorite = $this->favoriteRepository->isFavorite($user, $listing);

        return $this->json(['isFavorite' => $isFavorite]);
    }
}
