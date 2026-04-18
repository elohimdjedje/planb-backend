<?php

namespace App\Controller\Api;

use App\Entity\Offer;
use App\Entity\Listing;
use App\Entity\SaleContract;
use App\Repository\OfferRepository;
use App\Repository\ListingRepository;
use App\Repository\SaleContractRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/offers')]
#[IsGranted('ROLE_USER')]
class OfferController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OfferRepository $offerRepository,
        private ListingRepository $listingRepository,
        private SaleContractRepository $saleContractRepository,
        private ValidatorInterface $validator,
        private NotificationService $notificationService
    ) {}

    /**
     * Créer une offre d'achat
     */
    #[Route('', name: 'api_offer_create', methods: ['POST'])]
    public function createOffer(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $data = json_decode($request->getContent(), true);
        
        $listingId = $data['listing_id'] ?? null;
        $amount = $data['amount'] ?? null;
        $message = $data['message'] ?? null;
        $phone = $data['phone'] ?? null;

        if (!$listingId || !$amount) {
            return $this->json(['error' => 'Listing et montant requis'], 400);
        }

        $listing = $this->listingRepository->find($listingId);
        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        // Vérifications
        if ($listing->getUser()->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas faire une offre sur votre propre bien'], 400);
        }

        if ($listing->getType() !== 'vente') {
            return $this->json(['error' => 'Ce bien n\'est pas en vente'], 400);
        }

        if ($listing->getStatus() === 'sold') {
            return $this->json(['error' => 'Ce bien est déjà vendu'], 400);
        }

        // Vérifier si déjà une offre en attente
        if ($this->offerRepository->hasPendingOffer($user, $listing)) {
            return $this->json(['error' => 'Vous avez déjà une offre en attente pour ce bien'], 400);
        }

        $offer = new Offer();
        $offer->setListing($listing);
        $offer->setBuyer($user);
        $offer->setSeller($listing->getUser());
        $offer->setAmount($amount);
        $offer->setMessage($message);
        $offer->setBuyerPhone($phone);

        $errors = $this->validator->validate($offer);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], 400);
        }

        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        // Notifier le vendeur de la nouvelle offre
        $this->notificationService->notifyNewOffer(
            $listing->getUser(),
            $user,
            $listing->getTitle(),
            (float) $amount
        );

        return $this->json([
            'message' => 'Offre envoyée avec succès',
            'data' => $offer->toArray()
        ], 201);
    }

    /**
     * Liste des offres (selon le rôle)
     */
    #[Route('', name: 'api_offers_list', methods: ['GET'])]
    public function listOffers(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $role = $request->query->get('role', 'all'); // buyer, seller, all
        $status = $request->query->get('status');

        $offers = [];

        if ($role === 'buyer' || $role === 'all') {
            $buyerOffers = $this->offerRepository->findByBuyer($user, $status);
            foreach ($buyerOffers as $offer) {
                $data = $offer->toArray();
                $data['role'] = 'buyer';
                $offers[] = $data;
            }
        }

        if ($role === 'seller' || $role === 'all') {
            $sellerOffers = $this->offerRepository->findBySeller($user, $status);
            foreach ($sellerOffers as $offer) {
                $data = $offer->toArray();
                $data['role'] = 'seller';
                $offers[] = $data;
            }
        }

        // Trier par date
        usort($offers, fn($a, $b) => strtotime($b['createdAt']) - strtotime($a['createdAt']));

        return $this->json([
            'data' => $offers,
            'total' => count($offers)
        ]);
    }

    /**
     * Détail d'une offre
     */
    #[Route('/{id}', name: 'api_offer_detail', methods: ['GET'])]
    public function getOffer(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer) {
            return $this->json(['error' => 'Offre non trouvée'], 404);
        }

        // Vérifier que l'utilisateur est concerné
        if ($offer->getBuyer()->getId() !== $user->getId() && 
            $offer->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        $data = $offer->toArray();
        $data['role'] = $offer->getBuyer()->getId() === $user->getId() ? 'buyer' : 'seller';

        return $this->json(['data' => $data]);
    }

    /**
     * Accepter une offre
     */
    #[Route('/{id}/accept', name: 'api_offer_accept', methods: ['POST'])]
    public function acceptOffer(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer) {
            return $this->json(['error' => 'Offre non trouvée'], 404);
        }

        if ($offer->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if (!$offer->isPending()) {
            return $this->json(['error' => 'Cette offre ne peut plus être acceptée'], 400);
        }

        $data = json_decode($request->getContent(), true);

        $offer->setStatus(Offer::STATUS_ACCEPTED);
        $offer->setRespondedAt(new \DateTime());
        if (isset($data['response'])) {
            $offer->setSellerResponse($data['response']);
        }

        // Mettre le listing en statut "reserved" ou "negotiation"
        $listing = $offer->getListing();
        $listing->setStatus('reserved');

        // Rejeter les autres offres en attente
        $otherOffers = $this->offerRepository->findByListing($listing, Offer::STATUS_PENDING);
        foreach ($otherOffers as $otherOffer) {
            if ($otherOffer->getId() !== $offer->getId()) {
                $otherOffer->setStatus(Offer::STATUS_REJECTED);
                $otherOffer->setSellerResponse('Une autre offre a été acceptée');
            }
        }

        $this->entityManager->flush();

        // Créer automatiquement un contrat de vente draft
        $saleContract = $this->saleContractRepository->findByOffer($offer);
        if (!$saleContract) {
            $commission   = (float) $offer->getAmount() * SaleContract::COMMISSION_RATE;
            $saleContract = new SaleContract();
            $saleContract->setOffer($offer);
            $saleContract->setBuyer($offer->getBuyer());
            $saleContract->setSeller($offer->getSeller());
            $saleContract->setListing($listing);
            $saleContract->setSalePrice($offer->getAmount());
            $saleContract->setCommissionAmount((string) $commission);
            $this->entityManager->persist($saleContract);
            $this->entityManager->flush();
            // Générer l'identifiant unique après le premier flush (ID disponible)
            $saleContract->setUniqueContractId(
                sprintf('PLANB-VENTE-%d-%05d', (int) date('Y'), $saleContract->getId())
            );
            $this->entityManager->flush();
        }

        // Notifier l'acheteur que son offre a été acceptée
        $this->notificationService->notifyOfferAccepted(
            $offer->getBuyer(),
            $user,
            $listing->getTitle(),
            (float) $offer->getAmount()
        );

        return $this->json([
            'message'          => 'Offre acceptée',
            'data'             => $offer->toArray(),
            'sale_contract_id' => $saleContract->getId(),
        ]);
    }

    /**
     * Refuser une offre
     */
    #[Route('/{id}/reject', name: 'api_offer_reject', methods: ['POST'])]
    public function rejectOffer(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer) {
            return $this->json(['error' => 'Offre non trouvée'], 404);
        }

        if ($offer->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if (!$offer->isPending()) {
            return $this->json(['error' => 'Cette offre ne peut plus être refusée'], 400);
        }

        $data = json_decode($request->getContent(), true);

        $offer->setStatus(Offer::STATUS_REJECTED);
        $offer->setRespondedAt(new \DateTime());
        if (isset($data['reason'])) {
            $offer->setSellerResponse($data['reason']);
        }

        $this->entityManager->flush();

        // Notifier l'acheteur que son offre a été refusée
        $this->notificationService->notifyOfferRejected(
            $offer->getBuyer(),
            $user,
            $offer->getListing()->getTitle()
        );

        return $this->json([
            'message' => 'Offre refusée',
            'data' => $offer->toArray()
        ]);
    }

    /**
     * Faire une contre-offre
     */
    #[Route('/{id}/counter', name: 'api_offer_counter', methods: ['POST'])]
    public function counterOffer(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer) {
            return $this->json(['error' => 'Offre non trouvée'], 404);
        }

        if ($offer->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if (!$offer->isPending()) {
            return $this->json(['error' => 'Cette offre ne peut plus recevoir de contre-offre'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $counterAmount = $data['amount'] ?? null;

        if (!$counterAmount) {
            return $this->json(['error' => 'Montant de la contre-offre requis'], 400);
        }

        $offer->setStatus(Offer::STATUS_COUNTER_OFFER);
        $offer->setCounterOfferAmount($counterAmount);
        $offer->setRespondedAt(new \DateTime());
        $offer->setExpiresAt(new \DateTime('+7 days')); // Renouveler l'expiration
        if (isset($data['message'])) {
            $offer->setSellerResponse($data['message']);
        }

        $this->entityManager->flush();

        // Notifier l'acheteur de la contre-offre
        $this->notificationService->notifyCounterOffer(
            $offer->getBuyer(),
            $user,
            $offer->getListing()->getTitle(),
            (float) $counterAmount
        );

        return $this->json([
            'message' => 'Contre-offre envoyée',
            'data' => $offer->toArray()
        ]);
    }

    /**
     * Accepter une contre-offre (par l'acheteur)
     */
    #[Route('/{id}/accept-counter', name: 'api_offer_accept_counter', methods: ['POST'])]
    public function acceptCounterOffer(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer) {
            return $this->json(['error' => 'Offre non trouvée'], 404);
        }

        if ($offer->getBuyer()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if ($offer->getStatus() !== Offer::STATUS_COUNTER_OFFER) {
            return $this->json(['error' => 'Aucune contre-offre à accepter'], 400);
        }

        // Mettre à jour le montant avec la contre-offre
        $offer->setAmount($offer->getCounterOfferAmount());
        $offer->setStatus(Offer::STATUS_ACCEPTED);
        $offer->setUpdatedAt(new \DateTime());

        // Mettre le listing en réservé
        $listing = $offer->getListing();
        $listing->setStatus('reserved');

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Contre-offre acceptée',
            'data' => $offer->toArray()
        ]);
    }

    /**
     * Annuler une offre (par l'acheteur)
     */
    #[Route('/{id}/cancel', name: 'api_offer_cancel', methods: ['POST'])]
    public function cancelOffer(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer) {
            return $this->json(['error' => 'Offre non trouvée'], 404);
        }

        if ($offer->getBuyer()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if ($offer->getStatus() === Offer::STATUS_ACCEPTED) {
            return $this->json(['error' => 'Impossible d\'annuler une offre acceptée'], 400);
        }

        $wasCounterOffer = $offer->getStatus() === Offer::STATUS_COUNTER_OFFER;

        $offer->setStatus(Offer::STATUS_CANCELLED);
        $this->entityManager->flush();

        // Notifier le vendeur si une contre-offre était en attente
        if ($wasCounterOffer) {
            $this->notificationService->notifyOfferCancelled(
                $offer->getSeller(),
                $user,
                $offer->getListing()->getTitle()
            );
        }

        return $this->json([
            'message' => 'Offre annulée',
            'data' => $offer->toArray()
        ]);
    }

    /**
     * Offres pour un listing (vendeur uniquement)
     */
    #[Route('/listing/{listingId}', name: 'api_offers_by_listing', methods: ['GET'])]
    public function getOffersByListing(int $listingId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $listing = $this->listingRepository->find($listingId);
        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        $offers = $this->offerRepository->findByListing($listing);
        $bestOffer = $this->offerRepository->getBestOffer($listing);

        return $this->json([
            'data' => array_map(fn($o) => $o->toArray(), $offers),
            'total' => count($offers),
            'pendingCount' => $this->offerRepository->countPendingOffers($listing),
            'bestOffer' => $bestOffer?->toArray()
        ]);
    }

    /**
     * Statistiques des offres
     */
    #[Route('/stats', name: 'api_offers_stats', methods: ['GET'])]
    public function getStats(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $sellerStats = $this->offerRepository->getSellerStats($user);
        $buyerOffers = $this->offerRepository->findByBuyer($user);

        return $this->json([
            'asSeller' => $sellerStats,
            'asBuyer' => [
                'total' => count($buyerOffers),
                'pending' => count(array_filter($buyerOffers, fn($o) => $o->isPending())),
                'accepted' => count(array_filter($buyerOffers, fn($o) => $o->getStatus() === Offer::STATUS_ACCEPTED)),
            ]
        ]);
    }

    /**
     * Confirmer la vente — passe le listing à 'sold' (vendeur uniquement, offre acceptée)
     */
    #[Route('/{id}/mark-sold', name: 'api_offer_mark_sold', methods: ['POST'])]
    public function markSold(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer) {
            return $this->json(['error' => 'Offre introuvable'], 404);
        }

        if ($offer->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if ($offer->getStatus() !== Offer::STATUS_ACCEPTED) {
            return $this->json(['error' => 'Seule une offre acceptée peut confirmer la vente'], 400);
        }

        $listing = $offer->getListing();
        if ($listing->getStatus() === 'sold') {
            return $this->json(['error' => 'Ce bien est déjà marqué comme vendu'], 400);
        }

        $listing->setStatus('sold');
        $this->entityManager->flush();

        // Notifier l'acheteur
        $this->notificationService->notifyOfferAccepted(
            $offer->getBuyer(),
            $user,
            $listing->getTitle(),
            (float) $offer->getAmount()
        );

        return $this->json([
            'message' => 'Bien marqué comme vendu',
            'data' => $offer->toArray()
        ]);
    }
}
