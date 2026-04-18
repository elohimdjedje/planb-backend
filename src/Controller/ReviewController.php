<?php

namespace App\Controller;

use App\Entity\Review;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Repository\ListingRepository;
use App\Repository\UserRepository;
use App\Service\NotificationManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/reviews')]
class ReviewController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReviewRepository $reviewRepository,
        private ListingRepository $listingRepository,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        private NotificationManagerService $notificationManager
    ) {
    }

    /**
     * Créer un nouvel avis
     */
    #[Route('', name: 'review_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Validation des champs requis
        if (!isset($data['listingId'], $data['rating'])) {
            return $this->json([
                'error' => 'L\'ID de l\'annonce et la note sont requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        $listing = $this->listingRepository->find($data['listingId']);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier que l'utilisateur ne note pas sa propre annonce
        if ($listing->getUser()->getId() === $user->getId()) {
            return $this->json([
                'error' => 'Vous ne pouvez pas noter votre propre annonce'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier si l'utilisateur a déjà laissé un avis
        if ($this->reviewRepository->hasUserReviewedListing($user, $listing)) {
            return $this->json([
                'error' => 'Vous avez déjà laissé un avis pour cette annonce'
            ], Response::HTTP_BAD_REQUEST);
        }

        $review = new Review();
        $review->setListing($listing)
            ->setReviewer($user)
            ->setSeller($listing->getUser())
            ->setRating((int) $data['rating'])
            ->setReviewType($data['reviewType'] ?? 'transaction');

        if (isset($data['comment']) && !empty($data['comment'])) {
            $review->setComment($data['comment']);
        }

        // Pour les annonces de vacances (hotel, résidence), on marque comme vérifié automatiquement
        if (in_array($listing->getSubcategory(), ['hotel', 'residence-meublee', 'vacances'])) {
            $review->setReviewType('vacation');
            $review->setIsVerified(true);
        }

        $errors = $this->validator->validate($review);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            
            return $this->json([
                'error' => 'Erreur de validation',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->entityManager->persist($review);
            $this->entityManager->flush();

            // Notifier le vendeur qu'il a reçu un nouvel avis
            $this->notificationManager->notifyReviewReceived(
                $review->getSeller(),
                $review->getReviewer(),
                $review->getRating(),
                $review->getComment() ?? '',
                $review->getListing()
            );

            return $this->json([
                'message' => 'Avis ajouté avec succès',
                'review' => [
                    'id' => $review->getId(),
                    'rating' => $review->getRating(),
                    'comment' => $review->getComment(),
                    'createdAt' => $review->getCreatedAt()?->format('c')
                ]
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de la création de l\'avis',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtenir les avis d'un vendeur
     */
    #[Route('/seller/{sellerId}', name: 'review_seller', methods: ['GET'])]
    public function getSellerReviews(int $sellerId, Request $request): JsonResponse
    {
        $seller = $this->userRepository->find($sellerId);
        
        if (!$seller) {
            return $this->json(['error' => 'Vendeur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 10);

        $reviews = $this->reviewRepository->getReviewsForSeller($seller, $page, $limit);
        $averageRating = $this->reviewRepository->getAverageRatingForSeller($seller);
        $totalReviews = $this->reviewRepository->getTotalReviewsForSeller($seller);
        $distribution = $this->reviewRepository->getRatingDistributionForSeller($seller);

        $reviewsData = array_map(function ($review) {
            $reviewer = $review->getReviewer();
            $listing = $review->getListing();
            
            return [
                'id' => $review->getId(),
                'rating' => $review->getRating(),
                'comment' => $review->getComment(),
                'reviewType' => $review->getReviewType(),
                'isVerified' => $review->isVerified(),
                'createdAt' => $review->getCreatedAt()?->format('c'),
                'reviewer' => [
                    'id' => $reviewer->getId(),
                    'firstName' => $reviewer->getFirstName(),
                    'profilePicture' => $reviewer->getProfilePicture()
                ],
                'listing' => [
                    'id' => $listing->getId(),
                    'title' => $listing->getTitle(),
                    'category' => $listing->getCategory(),
                    'subcategory' => $listing->getSubcategory()
                ]
            ];
        }, $reviews);

        return $this->json([
            'reviews' => $reviewsData,
            'stats' => [
                'averageRating' => $averageRating,
                'totalReviews' => $totalReviews,
                'distribution' => $distribution
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalReviews
            ]
        ]);
    }

    /**
     * Obtenir les avis d'une annonce
     */
    #[Route('/listing/{listingId}', name: 'review_listing', methods: ['GET'])]
    public function getListingReviews(int $listingId): JsonResponse
    {
        $listing = $this->listingRepository->find($listingId);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        $reviews = $this->reviewRepository->getReviewsForListing($listing);

        $reviewsData = array_map(function ($review) {
            $reviewer = $review->getReviewer();
            
            return [
                'id' => $review->getId(),
                'rating' => $review->getRating(),
                'comment' => $review->getComment(),
                'createdAt' => $review->getCreatedAt()?->format('c'),
                'reviewer' => [
                    'id' => $reviewer->getId(),
                    'firstName' => $reviewer->getFirstName(),
                    'profilePicture' => $reviewer->getProfilePicture()
                ]
            ];
        }, $reviews);

        // Calculer la note moyenne de cette annonce
        $totalRating = 0;
        foreach ($reviews as $review) {
            $totalRating += $review->getRating();
        }
        $averageRating = count($reviews) > 0 ? round($totalRating / count($reviews), 1) : 0;

        return $this->json([
            'reviews' => $reviewsData,
            'averageRating' => $averageRating,
            'totalReviews' => count($reviews)
        ]);
    }

    /**
     * Supprimer un avis (seulement son propre avis)
     */
    #[Route('/{id}', name: 'review_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $review = $this->reviewRepository->find($id);
        
        if (!$review) {
            return $this->json(['error' => 'Avis introuvable'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier que c'est bien l'auteur de l'avis
        if ($review->getReviewer()->getId() !== $user->getId()) {
            return $this->json([
                'error' => 'Vous ne pouvez supprimer que vos propres avis'
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->entityManager->remove($review);
            $this->entityManager->flush();

            return $this->json(['message' => 'Avis supprimé avec succès']);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de la suppression de l\'avis',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
