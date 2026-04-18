<?php

namespace App\Controller\Api;

use App\Entity\Review;
use App\Entity\ReviewStats;
use App\Entity\Listing;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Repository\ReviewStatsRepository;
use App\Repository\ListingRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/reviews')]
class ReviewController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private ReviewRepository $reviewRepository;
    private ReviewStatsRepository $statsRepository;
    private ListingRepository $listingRepository;
    private NotificationService $notificationService;
    private BookingRepository $bookingRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        ReviewRepository $reviewRepository,
        ReviewStatsRepository $statsRepository,
        ListingRepository $listingRepository,
        NotificationService $notificationService,
        BookingRepository $bookingRepository
    ) {
        $this->entityManager = $entityManager;
        $this->reviewRepository = $reviewRepository;
        $this->statsRepository = $statsRepository;
        $this->listingRepository = $listingRepository;
        $this->notificationService = $notificationService;
        $this->bookingRepository = $bookingRepository;
    }

    /**
     * Créer un avis sur une annonce
     */
    #[Route('', name: 'api_reviews_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();

        // Validation
        if (!isset($data['listingId']) || !isset($data['rating'])) {
            return $this->json([
                'success' => false,
                'message' => 'listingId et rating sont requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        $listing = $this->listingRepository->find($data['listingId']);
        if (!$listing) {
            return $this->json([
                'success' => false,
                'message' => 'Annonce introuvable'
            ], Response::HTTP_NOT_FOUND);
        }

        // Vérifier que l'utilisateur ne note pas sa propre annonce
        if ($listing->getUser() === $user) {
            return $this->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas noter votre propre annonce'
            ], Response::HTTP_FORBIDDEN);
        }

        // Vérifier qu'il n'a pas déjà laissé un avis
        $existing = $this->reviewRepository->findOneBy([
            'listing' => $listing,
            'reviewer' => $user
        ]);

        if ($existing) {
            return $this->json([
                'success' => false,
                'message' => 'Vous avez déjà laissé un avis sur cette annonce'
            ], Response::HTTP_CONFLICT);
        }

        // Vérifier que l'utilisateur a bien loué ce logement (statut active ou completed)
        if (!$this->bookingRepository->hasCompletedBookingForListing($user, $listing)) {
            return $this->json([
                'success' => false,
                'message' => 'Vous devez avoir séjourné dans ce logement pour laisser un avis'
            ], Response::HTTP_FORBIDDEN);
        }

        // Créer l'avis
        $review = new Review();
        $review->setListing($listing);
        $review->setReviewer($user);
        $review->setSeller($listing->getUser());
        $review->setRating((int) $data['rating']);
        
        if (isset($data['comment'])) {
            $review->setComment($data['comment']);
        }

        $review->setStatus('approved'); // Auto-approuvé pour la version MVP
        $review->setCreatedAt(new \DateTime());

        $this->entityManager->persist($review);

        // Mettre à jour les statistiques du vendeur
        $this->updateSellerStats($listing->getUser(), (int) $data['rating']);

        // Notifier le vendeur du nouvel avis
        $this->notificationService->notifyNewReview(
            $listing->getUser(),
            $user,
            $listing->getTitle(),
            (int) $data['rating']
        );

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Avis publié avec succès',
            'data' => $this->serializeReview($review)
        ], Response::HTTP_CREATED);
    }

    /**
     * Récupère les avis d'un vendeur
     */
    #[Route('/seller/{sellerId}', name: 'api_reviews_by_seller', methods: ['GET'])]
    public function getBySeller(int $sellerId, Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 20);
        
        $reviews = $this->reviewRepository->findBy(
            ['seller' => $sellerId, 'status' => 'approved'],
            ['createdAt' => 'DESC'],
            $limit
        );

        $stats = $this->statsRepository->findOneBy(['user' => $sellerId]);

        return $this->json([
            'success' => true,
            'data' => [
                'reviews' => array_map([$this, 'serializeReview'], $reviews),
                'stats' => $stats ? [
                    'totalReviews' => $stats->getTotalReviews(),
                    'averageRating' => (float) $stats->getAverageRating(),
                    'rating1Count' => $stats->getRating1Count(),
                    'rating2Count' => $stats->getRating2Count(),
                    'rating3Count' => $stats->getRating3Count(),
                    'rating4Count' => $stats->getRating4Count(),
                    'rating5Count' => $stats->getRating5Count(),
                ] : null
            ]
        ]);
    }

    /**
     * Récupère les avis d'une annonce
     */
    #[Route('/listing/{listingId}', name: 'api_reviews_by_listing', methods: ['GET'])]
    public function getByListing(int $listingId): JsonResponse
    {
        $reviews = $this->reviewRepository->findBy(
            ['listing' => $listingId, 'status' => 'approved'],
            ['createdAt' => 'DESC']
        );

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'serializeReview'], $reviews)
        ]);
    }

    /**
     * Répondre à un avis (vendeur uniquement)
     */
    #[Route('/{id}/reply', name: 'api_reviews_reply', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reply(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();

        if (!isset($data['response'])) {
            return $this->json([
                'success' => false,
                'message' => 'La réponse est requise'
            ], Response::HTTP_BAD_REQUEST);
        }

        $review = $this->reviewRepository->find($id);
        if (!$review) {
            return $this->json([
                'success' => false,
                'message' => 'Avis introuvable'
            ], Response::HTTP_NOT_FOUND);
        }

        // Vérifier que c'est bien le vendeur
        if ($review->getSeller() !== $user) {
            return $this->json([
                'success' => false,
                'message' => 'Vous ne pouvez répondre qu\'aux avis sur vos annonces'
            ], Response::HTTP_FORBIDDEN);
        }

        $review->setSellerResponse($data['response']);
        $review->setRespondedAt(new \DateTime());

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Réponse publiée',
            'data' => $this->serializeReview($review)
        ]);
    }

    /**
     * Met à jour les statistiques d'un vendeur
     */
    private function updateSellerStats($seller, int $newRating): void
    {
        $stats = $this->statsRepository->findOrCreateForUser($seller);
        
        $stats->setTotalReviews($stats->getTotalReviews() + 1);
        $stats->incrementRatingCount($newRating);
        $stats->recalculateAverageRating();
        $stats->setLastUpdated(new \DateTime());

        $this->entityManager->persist($stats);
    }

    /**
     * Sérialise un avis pour l'API
     */
    private function serializeReview(Review $review): array
    {
        return [
            'id' => $review->getId(),
            'rating' => $review->getRating(),
            'comment' => $review->getComment(),
            'sellerResponse' => $review->getSellerResponse(),
            'createdAt' => $review->getCreatedAt()->format('c'),
            'respondedAt' => $review->getRespondedAt()?->format('c'),
            'reviewer' => [
                'id' => $review->getReviewer()->getId(),
                'name' => $review->getReviewer()->getFirstName(),
                'initials' => $review->getReviewer()->getInitials(),
            ],
            'listing' => [
                'id' => $review->getListing()->getId(),
                'title' => $review->getListing()->getTitle(),
            ]
        ];
    }
}
