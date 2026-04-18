<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Entity\User;
use App\Entity\Image;
use App\Repository\ListingRepository;
use App\Repository\ListingViewRepository;
use App\Repository\ReviewRepository;
use App\Service\ViewCounterService;
use App\Service\NotificationManagerService;
use App\Service\ScopeVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/listings')]
class ListingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private ListingRepository $listingRepository,
        private ListingViewRepository $listingViewRepository,
        private ViewCounterService $viewCounterService,
        private ReviewRepository $reviewRepository,
        private NotificationManagerService $notificationManager,
        private ScopeVerificationService $scopeVerificationService
    ) {
    }

    /**
     * Debug helper pour le mode agent
     */
    private function debugLog(string $hypothesisId, string $location, string $message, array $data = []): void
    {
        try {
            $logPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planb_debug.log';
            $entry = [
                'sessionId' => 'debug-session',
                'runId' => 'pre-fix-backend',
                'hypothesisId' => $hypothesisId,
                'location' => $location,
                'message' => $message,
                'data' => $data,
                'timestamp' => (int) (microtime(true) * 1000),
            ];
            @file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Ne jamais casser la prod pour les logs
        }
    }

    /**
     * Auto-expire les annonces actives dont expiresAt est passé
     */
    private function autoExpireListings(): void
    {
        $now = new \DateTime();
        $qb = $this->entityManager->createQueryBuilder()
            ->update('App\Entity\Listing', 'l')
            ->set('l.status', ':expired')
            ->where('l.status = :active')
            ->andWhere('l.expiresAt IS NOT NULL')
            ->andWhere('l.expiresAt < :now')
            ->setParameter('expired', 'expired')
            ->setParameter('active', 'active')
            ->setParameter('now', $now);
        $qb->getQuery()->execute();
    }

    #[Route('', name: 'listings_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->autoExpireListings();

        $limit = min((int) $request->query->get('limit', 20), 200);
        $lastId = $request->query->get('lastId');

        // Récupérer les filtres depuis la requête
        $filters = [];
        
        if ($request->query->has('search')) {
            $filters['search'] = $request->query->get('search');
        }
        
        if ($request->query->has('category')) {
            $filters['category'] = $request->query->get('category');
        }
        
        if ($request->query->has('subcategory')) {
            $filters['subcategory'] = $request->query->get('subcategory');
        }
        
        if ($request->query->has('type')) {
            $filters['type'] = $request->query->get('type');
        }
        
        if ($request->query->has('country')) {
            $filters['country'] = $request->query->get('country');
        }
        
        if ($request->query->has('city')) {
            $filters['city'] = $request->query->get('city');
        }
        
        if ($request->query->has('commune')) {
            $filters['commune'] = $request->query->get('commune');
        }
        
        if ($request->query->has('minPrice')) {
            $filters['priceMin'] = (float) $request->query->get('minPrice');
        }
        
        if ($request->query->has('maxPrice')) {
            $filters['priceMax'] = (float) $request->query->get('maxPrice');
        }
        
        if ($request->query->has('userId')) {
            $filters['userId'] = (int) $request->query->get('userId');
        }

        // Log debug pour la liste des annonces (H5)
        $this->debugLog(
            'H5',
            'ListingController::list',
            'Liste des annonces',
            [
                'limit' => $limit,
                'lastId' => $lastId,
                'filters' => $filters,
            ]
        );

        // Si des filtres sont présents, utiliser searchListings, sinon findActiveListings
        if (count($filters) > 0) {
            $listings = $this->listingRepository->searchListings($filters, $limit);
        } else {
            $listings = $this->listingRepository->findActiveListings($limit, $lastId);
        }

        $response = $this->json([
            'data' => array_map(fn($listing) => $this->serializeListing($listing), $listings),
            'hasMore' => count($listings) === $limit,
            'lastId' => count($listings) > 0 ? $listings[count($listings) - 1]->getId() : null
        ]);

        // Cache public 2 minutes si pas de filtres ni de recherche utilisateur
        if (count($filters) === 0) {
            $response->setPublic();
            $response->setMaxAge(120);
            $response->headers->set('Vary', 'Accept-Encoding');
        }

        return $response;
    }

    #[Route('/pro', name: 'listings_pro', methods: ['GET'])]
    public function getProListings(Request $request): JsonResponse
    {
        $limit = min((int) $request->query->get('limit', 10), 20);

        // Log debug pour les annonces PRO (H6)
        $this->debugLog(
            'H6',
            'ListingController::getProListings',
            'Annonces PRO',
            [
                'limit' => $limit,
            ]
        );

        // Récupérer les annonces des vendeurs PRO
        $proListings = $this->listingRepository->findProListings($limit);
        
        $response = $this->json([
            'data' => array_map(fn($listing) => $this->serializeListing($listing), $proListings),
            'total' => count($proListings)
        ]);
        $response->setPublic();
        $response->setMaxAge(120);
        $response->headers->set('Vary', 'Accept-Encoding');

        return $response;
    }

    /**
     * Récupérer les annonces récentes (moins d'une semaine)
     * Pour la section "Top Annonces"
     */
    #[Route('/recent', name: 'listings_recent', methods: ['GET'])]
    public function getRecentListings(Request $request): JsonResponse
    {
        $limit = min((int) $request->query->get('limit', 20), 50);
        $category = $request->query->get('category');

        // Log debug pour les annonces récentes (H7)
        $this->debugLog(
            'H7',
            'ListingController::getRecentListings',
            'Annonces récentes',
            [
                'limit' => $limit,
                'category' => $category,
            ]
        );

        // Récupérer les annonces récentes (moins d'une semaine)
        $recentListings = $this->listingRepository->findRecentListings($limit);
        
        // Filtrer par catégorie si spécifiée
        if ($category && $category !== 'all') {
            $recentListings = array_filter($recentListings, fn($listing) => $listing->getCategory() === $category);
            $recentListings = array_values($recentListings); // Réindexer le tableau
        }
        
        $response = $this->json([
            'data' => array_map(fn($listing) => $this->serializeListing($listing), $recentListings),
            'total' => count($recentListings)
        ]);
        $response->setPublic();
        $response->setMaxAge(120);
        $response->headers->set('Vary', 'Accept-Encoding');

        return $response;
    }

    /**
     * Statistiques publiques de la plateforme
     * GET /api/v1/listings/stats
     */
    #[Route('/stats', name: 'listings_stats', methods: ['GET'])]
    public function getPublicStats(): JsonResponse
    {
        // Nombre d'annonces actives
        $activeListings = $this->listingRepository->count(['status' => 'active']);
        
        // Nombre total d'utilisateurs
        $totalUsers = $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from('App\Entity\User', 'u')
            ->getQuery()
            ->getSingleScalarResult();
        
        // Nombre de pays couverts (distinct countries from listings)
        $countries = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT l.country)')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        // Nombre de vendeurs certifiés (identité vérifiée)
        $certifiedSellers = $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from('App\Entity\User', 'u')
            ->where('u.verificationStatus = :status')
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getSingleScalarResult();

        $response = $this->json([
            'stats' => [
                'activeListings'   => (int) $activeListings,
                'totalUsers'       => (int) $totalUsers,
                'countries'        => (int) $countries,
                'certifiedSellers' => (int) $certifiedSellers,
            ]
        ]);
        $response->setPublic();
        $response->setMaxAge(300); // Stats: cache 5 minutes
        $response->headers->set('Vary', 'Accept-Encoding');

        return $response;
    }

    /**
     * Récupérer les annonces de l'utilisateur connecté
     * GET /api/v1/listings/my
     */
    #[Route('/my', name: 'listings_my', methods: ['GET'])]
    public function getMyListings(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $listings = $this->listingRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->json([
            'data' => array_map(fn($listing) => $this->serializeListing($listing), $listings),
            'total' => count($listings)
        ]);
    }

    #[Route('/{id}', name: 'listings_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        // Enregistrer une vue unique seulement si ce n'est pas le propriétaire
        $currentUser = $this->getUser();
        $isOwner = $currentUser && $currentUser instanceof User && $currentUser->getId() === $listing->getUser()->getId();
        
        if (!$isOwner) {
            // Utiliser le service de comptage unique
            $this->viewCounterService->recordView($listing, $currentUser instanceof User ? $currentUser : null);
        }

        return $this->json($this->serializeListing($listing, true));
    }

    #[Route('', name: 'listings_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // ====== VÉRIFICATION PAR SCOPE ======
        // Vérifier que l'utilisateur est certifié pour la catégorie choisie
        $category = $data['category'] ?? null;
        $subcategory = $data['subcategory'] ?? null;
        
        $scopeCheck = $this->scopeVerificationService->canUserPublish($user, $category, $subcategory);
        
        if (!$scopeCheck['canPublish']) {
            return $this->json([
                'error' => 'VERIFICATION_REQUIRED',
                'message' => 'Vous devez être certifié pour publier dans cette catégorie.',
                'requiresVerification' => true,
                'requiredScope' => $scopeCheck['requiredScope'],
                'scopeDisplayName' => $scopeCheck['scopeDisplayName'] ?? null,
                'scopeIcon' => $scopeCheck['scopeIcon'] ?? null,
                'verificationStatus' => $scopeCheck['status'],
                'requiredDocs' => $scopeCheck['requiredDocs'] ?? [],
                'missingDocs' => $scopeCheck['missingDocs'] ?? [],
                'rejectionReason' => $scopeCheck['rejectionReason'] ?? null,
            ], Response::HTTP_FORBIDDEN);
        }

        // ====== LIMITE FREE (4 annonces max) ======
        if (!$user->isPro()) {
            $userListingsCount = count($user->getListings()->filter(fn($l) => $l->getStatus() === 'active'));
            
            if ($userListingsCount >= 4) {
                return $this->json([
                    'error' => 'QUOTA_EXCEEDED',
                    'message' => 'Vous avez atteint la limite de 4 annonces actives en mode gratuit. Passez PRO pour publier sans limite.',
                    'currentListings' => $userListingsCount,
                    'maxListings' => 4
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Créer l'annonce
        $listing = new Listing();
        $listing->setUser($user)
            ->setTitle($data['title'])
            ->setDescription($data['description'])
            ->setPrice($data['price'])
            ->setPriceUnit($data['priceUnit'] ?? 'mois')
            ->setCurrency($data['currency'] ?? 'XOF')
            ->setCategory($data['category'])
            ->setSubcategory($data['subcategory'] ?? null)
            ->setType($data['type'] ?? 'vente')
            ->setCountry($data['country'] ?? 'CI')
            ->setCity($data['city'] ?? null)
            ->setCommune($data['commune'] ?? null)
            ->setQuartier($data['quartier'] ?? null)
            ->setAddress($data['address'] ?? null)
            ->setLatitude(isset($data['latitude']) ? (float) $data['latitude'] : null)
            ->setLongitude(isset($data['longitude']) ? (float) $data['longitude'] : null)
            ->setSpecifications($data['specifications'] ?? [])
            ->setContactPhone($data['contactPhone'] ?? null)
            ->setContactWhatsapp($data['contactWhatsapp'] ?? null)
            ->setContactEmail($data['contactEmail'] ?? null)
            ->setVirtualTourType($data['virtualTourType'] ?? null)
            ->setVirtualTourUrl($data['virtualTourUrl'] ?? null)
            ->setVirtualTourThumbnail($data['virtualTourThumbnail'] ?? null);

        // Statut : brouillon ou actif
        $isDraft = !empty($data['draft']);
        $listing->setStatus($isDraft ? 'draft' : 'active');

        // Définir la durée selon le type de compte (30j FREE / 60j PRO) — seulement si publié
        if (!$isDraft) {
            $duration = $user->isPro() ? 60 : 30;
            $listing->setExpiresAt(new \DateTime("+$duration days"));
        }

        // Valider
        $errors = $this->validator->validate($listing);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()][] = $error->getMessage();
            }
            return $this->json([
                'error' => 'Erreur de validation',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        // Gérer les images si présentes
        if (isset($data['images']) && is_array($data['images']) && count($data['images']) > 0) {
            $orderPosition = 0;
            foreach ($data['images'] as $imageUrl) {
                $image = new Image();
                $image->setUrl($imageUrl)
                    ->setUser($user)
                    ->setListing($listing)
                    ->setOrderPosition($orderPosition++)
                    ->setStatus('uploaded');
                
                $listing->addImage($image);
                $this->entityManager->persist($image);
            }
        }

        // Sauvegarder
        try {
            $this->entityManager->persist($listing);
            $this->entityManager->flush();

            // Notifier l'utilisateur que son annonce a été publiée (pas pour les brouillons)
            if (!$isDraft) {
                $this->notificationManager->notifyListingPublished($listing);
            }

            return $this->json([
                'message' => $isDraft ? 'Brouillon enregistré' : 'Annonce créée avec succès',
                'data' => $this->serializeListing($listing, true)
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de la création',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'listings_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        // ====== RESTRICTION FREE : modification payante sauf brouillons ======
        if (!$user->isPro() && $listing->getStatus() !== 'draft') {
            return $this->json([
                'error' => 'PAYMENT_REQUIRED',
                'message' => 'La modification d\'annonces publiées coûte 1 000 FCFA pour les comptes gratuits.',
                'requiresPayment' => true,
                'amount' => 1000
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        // Mettre à jour les champs modifiables
        if (isset($data['title'])) {
            $listing->setTitle($data['title']);
        }
        if (isset($data['description'])) {
            $listing->setDescription($data['description']);
        }
        if (isset($data['price'])) {
            $listing->setPrice($data['price']);
        }
        if (isset($data['priceUnit'])) {
            $listing->setPriceUnit($data['priceUnit']);
        }
        if (isset($data['specifications'])) {
            $listing->setSpecifications($data['specifications']);
        }
        
        // ✅ AJOUT: Gérer le changement de statut (vendu/occupé)
        if (isset($data['status'])) {
            $oldStatus = $listing->getStatus();
            $newStatus = $data['status'];
            $listing->setStatus($newStatus);

            // Si on publie un brouillon → fixer expiresAt
            if ($oldStatus === 'draft' && $newStatus === 'active') {
                $duration = $user->isPro() ? 60 : 30;
                $listing->setExpiresAt(new \DateTime("+$duration days"));
            }
            
            // Notifier les utilisateurs qui ont cette annonce en favori
            if ($oldStatus !== $newStatus && in_array($newStatus, ['sold', 'expired', 'suspended'])) {
                $this->notificationManager->notifyFavoriteUnavailable($listing, $newStatus);
            }
        }
        
        // ✅ AJOUT: Gérer les champs de localisation
        if (isset($data['city'])) {
            $listing->setCity($data['city']);
        }
        if (isset($data['commune'])) {
            $listing->setCommune($data['commune']);
        }
        if (isset($data['quartier'])) {
            $listing->setQuartier($data['quartier']);
        }
        
        // ✅ AJOUT: Gérer les champs de catégorie
        if (isset($data['category'])) {
            $listing->setCategory($data['category']);
        }
        if (isset($data['subcategory'])) {
            $listing->setSubcategory($data['subcategory']);
        }
        if (isset($data['type'])) {
            $listing->setType($data['type']);
        }
        
        // ✅ AJOUT: Gérer les coordonnées de contact
        if (isset($data['contactPhone'])) {
            $listing->setContactPhone($data['contactPhone']);
        }
        if (isset($data['contactWhatsapp'])) {
            $listing->setContactWhatsapp($data['contactWhatsapp']);
        }
        if (isset($data['contactEmail'])) {
            $listing->setContactEmail($data['contactEmail']);
        }
        
        // ✅ AJOUT: Gérer le pays et l'adresse
        if (isset($data['country'])) {
            $listing->setCountry($data['country']);
        }
        if (isset($data['address'])) {
            $listing->setAddress($data['address']);
        }
        if (isset($data['latitude'])) {
            $listing->setLatitude((float) $data['latitude']);
        }
        if (isset($data['longitude'])) {
            $listing->setLongitude((float) $data['longitude']);
        }
        
        // Visite virtuelle
        if (isset($data['virtualTourType'])) {
            $listing->setVirtualTourType($data['virtualTourType']);
        }
        if (isset($data['virtualTourUrl'])) {
            $listing->setVirtualTourUrl($data['virtualTourUrl']);
        }
        if (isset($data['virtualTourThumbnail'])) {
            $listing->setVirtualTourThumbnail($data['virtualTourThumbnail']);
        }
        if (isset($data['virtualTourData'])) {
            $listing->setVirtualTourData($data['virtualTourData']);
        }
        
        // ✅ AJOUT: Gérer les images (remplacer toutes les images si fournies)
        if (isset($data['images']) && is_array($data['images'])) {
            // Supprimer les anciennes images
            foreach ($listing->getImages() as $oldImage) {
                $listing->removeImage($oldImage);
                $this->entityManager->remove($oldImage);
            }
            // Ajouter les nouvelles images
            $orderPosition = 0;
            foreach ($data['images'] as $imageUrl) {
                $image = new Image();
                $image->setUrl($imageUrl)
                    ->setUser($user)
                    ->setListing($listing)
                    ->setOrderPosition($orderPosition++)
                    ->setStatus('uploaded');
                $listing->addImage($image);
                $this->entityManager->persist($image);
            }
        }

        $listing->setUpdatedAt(new \DateTime());

        // Valider l'entité après modification
        $errors = $this->validator->validate($listing);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()][] = $error->getMessage();
            }
            return $this->json([
                'error' => 'Erreur de validation',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Annonce mise à jour',
            'data' => $this->serializeListing($listing, true)
        ]);
    }

    #[Route('/{id}', name: 'listings_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        // Notifier les utilisateurs qui ont cette annonce en favori AVANT suppression
        $this->notificationManager->notifyFavoriteUnavailable($listing, 'deleted');

        $this->entityManager->remove($listing);
        $this->entityManager->flush();

        return $this->json(['message' => 'Annonce supprimée avec succès']);
    }

    /**
     * Incrémenter le compteur de vues d'une annonce
     * Système anti-fraude : 1 utilisateur = 1 seule vue par annonce
     */
    #[Route('/{id}/view', name: 'app_listing_view', methods: ['POST'])]
    public function incrementView(int $id, Request $request): JsonResponse
    {
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        // Récupérer l'utilisateur connecté (si présent)
        $user = $this->getUser();
        $userId = $user instanceof User ? $user->getId() : null;

        // Le propriétaire ne génère jamais de vue sur sa propre annonce
        if ($userId !== null && $listing->getUser()->getId() === $userId) {
            return $this->json([
                'message' => 'Propriétaire - vue non comptée',
                'viewsCount' => $listing->getViewsCount(),
                'counted' => false
            ]);
        }

        // Récupérer l'IP (gérer les proxies)
        $ipAddress = $request->getClientIp() ?? '0.0.0.0';

        // Générer un fingerprint unique basé sur IP + User-Agent + données du body
        $data = json_decode($request->getContent(), true) ?? [];
        $clientFingerprint = $data['fingerprint'] ?? '';
        $userAgent = $request->headers->get('User-Agent', '');
        
        // Créer un fingerprint combiné (hash de IP + UA + fingerprint client)
        $fingerprint = hash('sha256', $ipAddress . '|' . $userAgent . '|' . $clientFingerprint);

        // Récupérer le referrer
        $referrer = $request->headers->get('Referer');

        // Enregistrer la vue (le repository gère les doublons)
        $viewCounted = $this->listingViewRepository->recordView(
            $listing,
            $userId,
            $ipAddress,
            $fingerprint,
            $userAgent,
            $referrer
        );

        return $this->json([
            'message' => $viewCounted ? 'Vue enregistrée' : 'Vue déjà comptée',
            'viewsCount' => $listing->getViewsCount(),
            'counted' => $viewCounted
        ]);
    }

    /**
     * Incrémenter le compteur de contacts d'une annonce
     */
    #[Route('/{id}/contact', name: 'app_listing_contact', methods: ['POST'])]
    public function incrementContact(int $id): JsonResponse
    {
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $listing->incrementContacts();
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Contact enregistré',
            'contactsCount' => $listing->getContactsCount()
        ]);
    }

    /**
     * Obtenir les statistiques détaillées d'une annonce
     * GET /api/v1/listings/{id}/stats
     */
    #[Route('/{id}/stats', name: 'app_listing_stats', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function getListingStats(int $id): JsonResponse
    {
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if (!$user instanceof User || $listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        // Statistiques de vues
        $viewStats = $this->listingViewRepository->getViewStats($listing);

        // Statistiques de favoris
        $favoritesCount = $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from('App\Entity\Favorite', 'f')
            ->where('f.listing = :listing')
            ->setParameter('listing', $listing)
            ->getQuery()
            ->getSingleScalarResult();

        // Statistiques de contacts
        $contactsCount = $listing->getContactsCount();

        // Statistiques par période (7 derniers jours)
        $sevenDaysAgo = new \DateTime('-7 days');
        $viewsLast7Days = $this->entityManager->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from('App\Entity\ListingView', 'v')
            ->where('v.listing = :listing')
            ->andWhere('v.viewedAt >= :date')
            ->setParameter('listing', $listing)
            ->setParameter('date', $sevenDaysAgo)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'stats' => [
                'views' => [
                    'total' => (int) ($viewStats['totalViews'] ?? $listing->getViewsCount()),
                    'uniqueUsers' => (int) ($viewStats['uniqueUsers'] ?? 0),
                    'uniqueIps' => (int) ($viewStats['uniqueIps'] ?? 0),
                    'last7Days' => (int) $viewsLast7Days,
                ],
                'favorites' => [
                    'total' => (int) $favoritesCount,
                ],
                'contacts' => [
                    'total' => $contactsCount,
                ],
                'performance' => [
                    'viewsPerDay' => $viewsLast7Days > 0 ? round($viewsLast7Days / 7, 2) : 0,
                    'conversionRate' => $viewsLast7Days > 0 ? round(($contactsCount / $viewsLast7Days) * 100, 2) : 0,
                ],
            ]
        ]);
    }

    /**
     * Obtenir les statistiques de toutes les annonces de l'utilisateur
     * GET /api/v1/listings/my/stats
     */
    #[Route('/my/stats', name: 'app_my_listings_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getMyListingsStats(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listings = $this->listingRepository->findBy(['user' => $user]);

        $totalViews = 0;
        $totalContacts = 0;
        $totalFavorites = 0;
        $activeListings = 0;
        $expiredListings = 0;

        // ✅ FIX: Batch favorites query instead of N+1
        $listingIds = array_map(fn($l) => $l->getId(), $listings);
        $favoriteCounts = [];
        if (!empty($listingIds)) {
            $results = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(f.listing) as listingId, COUNT(f.id) as cnt')
                ->from('App\Entity\Favorite', 'f')
                ->where('f.listing IN (:listings)')
                ->setParameter('listings', $listingIds)
                ->groupBy('f.listing')
                ->getQuery()
                ->getResult();
            foreach ($results as $row) {
                $favoriteCounts[$row['listingId']] = (int) $row['cnt'];
            }
        }

        foreach ($listings as $listing) {
            $totalViews += $listing->getViewsCount();
            $totalContacts += $listing->getContactsCount();
            
            if ($listing->getStatus() === 'active') {
                $activeListings++;
            } elseif ($listing->getStatus() === 'expired') {
                $expiredListings++;
            }

            $totalFavorites += $favoriteCounts[$listing->getId()] ?? 0;
        }

        return $this->json([
            'stats' => [
                'totalListings' => count($listings),
                'activeListings' => $activeListings,
                'expiredListings' => $expiredListings,
                'totalViews' => $totalViews,
                'totalContacts' => $totalContacts,
                'totalFavorites' => $totalFavorites,
                'averageViewsPerListing' => count($listings) > 0 ? round($totalViews / count($listings), 2) : 0,
                'averageContactsPerListing' => count($listings) > 0 ? round($totalContacts / count($listings), 2) : 0,
            ]
        ]);
    }

    private function serializeListing(Listing $listing, bool $detailed = false): array
    {
        // Calculer le score du vendeur (somme des vues + contacts de toutes ses annonces)
        $user = $listing->getUser();
        $sellerScore = 0;
        foreach ($user->getListings() as $userListing) {
            $sellerScore += $userListing->getViewsCount() + $userListing->getContactsCount();
        }
        
        // Stats de CETTE ANNONCE spécifique
        $listingAverageRating = $this->reviewRepository->getAverageRatingForListing($listing);
        $listingReviewsCount = $this->reviewRepository->getTotalReviewsForListing($listing);
        
        // Stats CUMULÉES du vendeur (toutes ses annonces)
        $sellerAverageRating = $this->reviewRepository->getAverageRatingForSeller($user);
        $sellerReviewsCount = $this->reviewRepository->getTotalReviewsForSeller($user);

        $data = [
            'id' => $listing->getId(),
            'title' => $listing->getTitle(),
            'description' => $detailed ? $listing->getDescription() : (mb_strlen($listing->getDescription(), 'UTF-8') > 150 ? mb_substr($listing->getDescription(), 0, 150, 'UTF-8') . '...' : $listing->getDescription()),
            'price' => (float) $listing->getPrice(),
            'priceUnit' => $listing->getPriceUnit(),
            'currency' => $listing->getCurrency(),
            'category' => $listing->getCategory(),
            'subcategory' => $listing->getSubcategory(),
            'type' => $listing->getType(),
            'country' => $listing->getCountry(),
            'city' => $listing->getCity(),
            'commune' => $listing->getCommune(),
            'quartier' => $listing->getQuartier(),
            'latitude' => $listing->getLatitude(),
            'longitude' => $listing->getLongitude(),
            'status' => $listing->getStatus(),
            'isFeatured' => $listing->isFeatured(),
            'viewsCount' => $listing->getViewsCount(),
            'imageCount' => count($listing->getImages()),
            'createdAt' => $listing->getCreatedAt()->format('c'),
            'expiresAt' => $listing->getExpiresAt() ? $listing->getExpiresAt()->format('c') : null,
            'mainImage' => $listing->getMainImage()?->getUrl(),
            // Visite virtuelle 360°
            'hasVirtualTour' => $listing->hasVirtualTour(),
            'virtualTour' => $listing->hasVirtualTour() ? [
                'type' => $listing->getVirtualTourType(),
                'url' => $listing->getVirtualTourUrl(),
                'thumbnail' => $listing->getVirtualTourThumbnail(),
            ] : null,
            // Stats des avis de cette annonce spécifique (pour la page détail)
            'averageRating' => $listingAverageRating > 0 ? $listingAverageRating : null,
            'reviewsCount' => $listingReviewsCount > 0 ? $listingReviewsCount : null,
            // Toujours inclure les infos user de base pour afficher le badge PRO et le nom
            'user' => [
                'id' => $user->getId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'accountType' => $user->getAccountType(),
                'isPro' => $user->isPro(),
                'isVerified' => $user->isIdentityVerified(),
                'verificationBadges' => $user->getVerificationBadges() ?? [],
                // Badge contextuel : certifié pour CETTE catégorie ?
                'isCertifiedForCategory' => $this->scopeVerificationService->isUserCertifiedForCategory(
                    $user,
                    $listing->getCategory(),
                    $listing->getSubcategory()
                ),
                'sellerScore' => $sellerScore,
                // Stats cumulées du vendeur (pour l'en-tête des cartes)
                'averageRating' => $sellerAverageRating > 0 ? $sellerAverageRating : null,
                'reviewsCount' => $sellerReviewsCount > 0 ? $sellerReviewsCount : null,
            ],
        ];

        if ($detailed) {
            $data['address'] = $listing->getAddress();
            $data['specifications'] = $listing->getSpecifications();
            $data['contactsCount'] = $listing->getContactsCount();
            $data['images'] = array_map(fn($img) => [
                'url' => $img->getUrl(),
                'thumbnailUrl' => $img->getThumbnailUrl(),
            ], $listing->getImages()->toArray());
            // Ajouter les infos de contact spécifiques à l'annonce
            $data['contactPhone'] = $listing->getContactPhone();
            $data['contactWhatsapp'] = $listing->getContactWhatsapp();
            $data['contactEmail'] = $listing->getContactEmail();
            // Ajouter les infos détaillées de l'user
            $data['user']['id'] = $listing->getUser()->getId();
            $data['user']['firstName'] = $listing->getUser()->getFirstName();
            $data['user']['lastName'] = $listing->getUser()->getLastName();
            $data['user']['phone'] = $listing->getUser()->getPhone();
            $data['user']['email'] = $listing->getUser()->getEmail();
            $data['user']['whatsappPhone'] = $listing->getUser()->getWhatsappPhone();
            $data['user']['city'] = $listing->getUser()->getCity();
            $data['user']['profilePicture'] = $listing->getUser()->getProfilePicture();
            $data['user']['createdAt'] = $listing->getUser()->getCreatedAt()?->format('c');
        }

        return $data;
    }
}
