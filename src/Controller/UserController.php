<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/users')]
class UserController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private ReviewRepository $reviewRepository,
        private ValidatorInterface $validator,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    /**
     * Mettre à jour le profil de l'utilisateur connecté
     */
    #[Route('/profile', name: 'app_user_update_profile', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);

        // Mise à jour des champs autorisés
        if (isset($data['firstName'])) {
            $user->setFirstName($data['firstName']);
        }

        if (isset($data['lastName'])) {
            $user->setLastName($data['lastName']);
        }

        if (isset($data['phone'])) {
            // Vérifier si le téléphone n'est pas déjà utilisé par un autre utilisateur
            $existingUser = $this->userRepository->findOneBy(['phone' => $data['phone']]);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                return $this->json(['error' => 'Ce numéro de téléphone est déjà utilisé'], 400);
            }
            $user->setPhone($data['phone']);
        }

        if (isset($data['city'])) {
            $user->setCity($data['city']);
        }

        if (isset($data['country'])) {
            $user->setCountry($data['country']);
        }

        if (isset($data['profilePicture'])) {
            $user->setProfilePicture($data['profilePicture']);
        }

        if (isset($data['bio'])) {
            $user->setBio($data['bio']);
        }

        // Ajouter le support du numéro WhatsApp
        if (isset($data['whatsappPhone'])) {
            $user->setWhatsappPhone($data['whatsappPhone']);
        }

        // Validation
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], 400);
        }

        $user->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Profil mis à jour avec succès',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'whatsappPhone' => $user->getWhatsappPhone(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'accountType' => $user->getAccountType(),
                'country' => $user->getCountry(),
                'city' => $user->getCity(),
                'profilePicture' => $user->getProfilePicture(),
                'bio' => $user->getBio(),
            ]
        ]);
    }

    /**
     * Changer le mot de passe
     */
    #[Route('/password', name: 'app_user_change_password', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);

        // Vérifier que les champs requis sont présents
        if (!isset($data['currentPassword']) || !isset($data['newPassword'])) {
            return $this->json([
                'error' => 'Mot de passe actuel et nouveau mot de passe requis'
            ], 400);
        }

        // Vérifier l'ancien mot de passe
        if (!$this->passwordHasher->isPasswordValid($user, $data['currentPassword'])) {
            return $this->json(['error' => 'Mot de passe actuel incorrect'], 400);
        }

        // Valider le nouveau mot de passe (min 8 caractères)
        if (strlen($data['newPassword']) < 8) {
            return $this->json([
                'error' => 'Le nouveau mot de passe doit contenir au moins 8 caractères'
            ], 400);
        }

        // Hasher et enregistrer le nouveau mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['newPassword']);
        $user->setPassword($hashedPassword);
        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Mot de passe modifié avec succès'
        ]);
    }

    /**
     * Obtenir les statistiques de l'utilisateur
     */
    #[Route('/stats', name: 'app_user_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getStats(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $listings = $user->getListings();
        $activeListings = $listings->filter(fn($listing) => $listing->getStatus() === 'active');
        
        $totalViews = 0;
        $totalContacts = 0;
        
        foreach ($listings as $listing) {
            $totalViews += $listing->getViewsCount();
            $totalContacts += $listing->getContactsCount();
        }

        return $this->json([
            'stats' => [
                'totalListings' => $listings->count(),
                'activeListings' => $activeListings->count(),
                'totalViews' => $totalViews,
                'totalContacts' => $totalContacts,
                'accountType' => $user->getAccountType(),
                'isPro' => $user->isPro(),
                'memberSince' => $user->getCreatedAt()->format('Y-m-d'),
                'subscriptionExpiresAt' => $user->getSubscriptionExpiresAt()?->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Supprimer son compte
     */
    #[Route('/account', name: 'app_user_delete_account', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteAccount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);

        // Vérifier le mot de passe pour confirmation
        if (!isset($data['password'])) {
            return $this->json([
                'error' => 'Mot de passe requis pour supprimer le compte'
            ], 400);
        }

        if (!$this->passwordHasher->isPasswordValid($user, $data['password'])) {
            return $this->json(['error' => 'Mot de passe incorrect'], 400);
        }

        // Suppression en cascade (listings, images, payments, subscription)
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Compte supprimé avec succès'
        ]);
    }

    /**
     * Obtenir le profil public d'un vendeur avec ses annonces
     */
    #[Route('/{id}/public-profile', name: 'app_user_public_profile', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function getPublicProfile(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Récupérer les annonces actives du vendeur
        $listings = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from('App\Entity\Listing', 'l')
            ->where('l.user = :user')
            ->andWhere('l.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'active')
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Sérialiser les annonces
        $listingsData = array_map(function($listing) {
            return [
                'id' => $listing->getId(),
                'title' => $listing->getTitle(),
                'price' => (float) $listing->getPrice(),
                'currency' => $listing->getCurrency(),
                'category' => $listing->getCategory(),
                'subcategory' => $listing->getSubcategory(),
                'type' => $listing->getType(),
                'city' => $listing->getCity(),
                'commune' => $listing->getCommune(),
                'viewsCount' => $listing->getViewsCount(),
                'mainImage' => $listing->getMainImage()?->getUrl(),
                'isFeatured' => $listing->isFeatured(),
                'createdAt' => $listing->getCreatedAt()->format('c'),
                // Ajouter les contacts de l'annonce
                'contactPhone' => $listing->getContactPhone(),
                'contactWhatsapp' => $listing->getContactWhatsapp(),
                'contactEmail' => $listing->getContactEmail(),
            ];
        }, $listings);

        // Calculer les statistiques
        $totalViews = array_sum(array_map(fn($l) => $l->getViewsCount(), $listings));
        $totalContacts = array_sum(array_map(fn($l) => $l->getContactsCount(), $listings));

        // Calculer la moyenne des avis et le nombre total
        $reviewStats = $this->reviewRepository->createQueryBuilder('r')
            ->select('AVG(r.rating) as averageRating, COUNT(r.id) as reviewsCount')
            ->where('r.seller = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        $averageRating = $reviewStats['averageRating'] ? (float) $reviewStats['averageRating'] : 0;
        $reviewsCount = (int) $reviewStats['reviewsCount'];

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'phone' => $user->getPhone(),
                'whatsappPhone' => $user->getWhatsappPhone() ?? $user->getPhone(),
                'city' => $user->getCity(),
                'country' => $user->getCountry(),
                'bio' => $user->getBio(),
                'profilePicture' => $user->getProfilePicture(),
                'accountType' => $user->getAccountType(),
                'isPro' => $user->isPro(),
                'isLifetimePro' => $user->isLifetimePro(),
                'memberSince' => $user->getCreatedAt()->format('Y'),
                'averageRating' => round($averageRating, 1),
                'reviewsCount' => $reviewsCount,
                'createdAt' => $user->getCreatedAt()->format('c'),
                'isVerified' => $user->isIdentityVerified(),
                'verificationBadges' => $user->getVerificationBadges() ?? [],
                'verificationCategory' => $user->getVerificationCategory(),
                'email' => $user->getEmail(),
            ],
            'stats' => [
                'activeListings' => count($listings),
                'totalViews' => $totalViews,
                'totalContacts' => $totalContacts,
            ],
            'listings' => $listingsData,
        ]);
    }

    /**
     * Obtenir la liste de ses propres annonces
     */
    #[Route('/my-listings', name: 'app_user_my_listings', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getMyListings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Auto-expirer les annonces dont la date est dépassée
        $now = new \DateTime();
        $this->entityManager->createQueryBuilder()
            ->update('App\Entity\Listing', 'l')
            ->set('l.status', ':expired')
            ->where('l.status = :active')
            ->andWhere('l.expiresAt IS NOT NULL')
            ->andWhere('l.expiresAt < :now')
            ->andWhere('l.user = :user')
            ->setParameter('expired', 'expired')
            ->setParameter('active', 'active')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $status = $request->query->get('status'); // active, draft, expired, sold
        $limit = $request->query->get('limit', 20);

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from('App\Entity\Listing', 'l')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($status) {
            $queryBuilder->andWhere('l.status = :status')
                ->setParameter('status', $status);
        }

        $listings = $queryBuilder->getQuery()->getResult();

        $data = array_map(function($listing) {
            return [
                'id' => $listing->getId(),
                'title' => $listing->getTitle(),
                'price' => $listing->getPrice(),
                'currency' => $listing->getCurrency(),
                'category' => $listing->getCategory(),
                'type' => $listing->getType(),
                'status' => $listing->getStatus(),
                'city' => $listing->getCity(),
                'viewsCount' => $listing->getViewsCount(),
                'contactsCount' => $listing->getContactsCount(),
                'isFeatured' => $listing->isFeatured(),
                'mainImage' => $listing->getMainImage()?->getUrl(),
                'createdAt' => $listing->getCreatedAt()->format('c'),
                'expiresAt' => $listing->getExpiresAt() ? $listing->getExpiresAt()->format('c') : null,
            ];
        }, $listings);

        return $this->json([
            'listings' => $data,
            'total' => count($data)
        ]);
    }
}
