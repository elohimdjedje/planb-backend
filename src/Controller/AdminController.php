<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\ListingRepository;
use App\Repository\PaymentRepository;
use App\Service\SecurityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')] // Double protection : access_control + annotation (défense en profondeur)
class AdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private ListingRepository $listingRepository,
        private PaymentRepository $paymentRepository,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * Dashboard admin - Statistiques globales
     */
    #[Route('/dashboard', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        // Statistiques utilisateurs
        $totalUsers = $this->userRepository->count([]);
        $freeUsers = $this->userRepository->count(['accountType' => 'FREE']);
        $proUsers = $this->userRepository->count(['accountType' => 'PRO']);

        // Statistiques annonces
        $totalListings = $this->listingRepository->count([]);
        $activeListings = $this->listingRepository->count(['status' => 'active']);
        $draftListings = $this->listingRepository->count(['status' => 'draft']);
        $expiredListings = $this->listingRepository->count(['status' => 'expired']);

        // Statistiques paiements
        $totalPayments = $this->paymentRepository->count([]);
        $completedPayments = $this->paymentRepository->count(['status' => 'completed']);
        $pendingPayments = $this->paymentRepository->count(['status' => 'pending']);

        // Revenus totaux
        $qb = $this->entityManager->createQueryBuilder();
        $totalRevenue = $qb->select('SUM(p.amount)')
            ->from('App\Entity\Payment', 'p')
            ->where('p.status = :completed')
            ->andWhere('p.currency = :xof')
            ->setParameter('completed', 'completed')
            ->setParameter('xof', 'XOF')
            ->getQuery()
            ->getSingleScalarResult();

        // Nouveaux utilisateurs ce mois
        $startOfMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        $newUsersThisMonth = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :start')
            ->setParameter('start', $startOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        // Nouvelles annonces ce mois
        $newListingsThisMonth = $this->listingRepository->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.createdAt >= :start')
            ->setParameter('start', $startOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'dashboard' => [
                'users' => [
                    'total' => $totalUsers,
                    'free' => $freeUsers,
                    'pro' => $proUsers,
                    'newThisMonth' => (int) $newUsersThisMonth
                ],
                'listings' => [
                    'total' => $totalListings,
                    'active' => $activeListings,
                    'draft' => $draftListings,
                    'expired' => $expiredListings,
                    'newThisMonth' => (int) $newListingsThisMonth
                ],
                'payments' => [
                    'total' => $totalPayments,
                    'completed' => $completedPayments,
                    'pending' => $pendingPayments
                ],
                'revenue' => [
                    'total' => (float) ($totalRevenue ?? 0),
                    'currency' => 'XOF'
                ]
            ]
        ]);
    }

    /**
     * Liste tous les utilisateurs
     */
    #[Route('/users', name: 'app_admin_users', methods: ['GET'])]
    public function getUsers(Request $request): JsonResponse
    {
        $limit = min($request->query->get('limit', 50), 100);
        $offset = $request->query->get('offset', 0);
        $accountType = $request->query->get('accountType'); // FREE, PRO
        $search = $request->query->get('search'); // email, phone

        $qb = $this->userRepository->createQueryBuilder('u')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('u.createdAt', 'DESC');

        if ($accountType) {
            $qb->andWhere('u.accountType = :type')
                ->setParameter('type', $accountType);
        }

        if ($search) {
            $qb->andWhere('u.email LIKE :search OR u.phone LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $users = $qb->getQuery()->getResult();

        // Compter le total
        $countQb = clone $qb;
        $countQb->select('COUNT(u.id)');
        $countQb->resetDQLPart('orderBy');
        $countQb->setFirstResult(0)->setMaxResults(null);
        $total = $countQb->getQuery()->getSingleScalarResult();

        $data = array_map(function($user) {
            return [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'fullName' => $user->getFullName(),
                'accountType' => $user->getAccountType(),
                'isLifetimePro' => $user->isLifetimePro(),
                'isVerified' => $user->isVerified(),
                'isEmailVerified' => $user->isEmailVerified(),
                'isPhoneVerified' => $user->isPhoneVerified(),
                'roles' => $user->getRoles(),
                'country' => $user->getCountry(),
                'city' => $user->getCity(),
                'subscriptionExpiresAt' => $user->getSubscriptionExpiresAt()?->format('c'),
                'createdAt' => $user->getCreatedAt()->format('c'),
                'totalListings' => $user->getListings()->count(),
                'totalPayments' => $user->getPayments()->count()
            ];
        }, $users);

        return $this->json([
            'users' => $data,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Détail d'un utilisateur
     */
    #[Route('/users/{id}', name: 'app_admin_user_detail', methods: ['GET'])]
    public function getUserDetail(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Statistiques de l'utilisateur
        $listings = $user->getListings();
        $payments = $user->getPayments();

        $totalRevenue = 0;
        foreach ($payments as $payment) {
            if ($payment->getStatus() === 'completed') {
                $totalRevenue += $payment->getAmount();
            }
        }

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'accountType' => $user->getAccountType(),
                'isLifetimePro' => $user->isLifetimePro(),
                'country' => $user->getCountry(),
                'city' => $user->getCity(),
                'profilePicture' => $user->getProfilePicture(),
                'isEmailVerified' => $user->isEmailVerified(),
                'isPhoneVerified' => $user->isPhoneVerified(),
                'subscriptionExpiresAt' => $user->getSubscriptionExpiresAt()?->format('c'),
                'createdAt' => $user->getCreatedAt()->format('c'),
                'updatedAt' => $user->getUpdatedAt()?->format('c')
            ],
            'stats' => [
                'totalListings' => $listings->count(),
                'activeListings' => $listings->filter(fn($l) => $l->getStatus() === 'active')->count(),
                'totalPayments' => $payments->count(),
                'completedPayments' => $payments->filter(fn($p) => $p->getStatus() === 'completed')->count(),
                'totalRevenue' => $totalRevenue,
                'currency' => 'XOF'
            ]
        ]);
    }

    /**
     * Mettre un utilisateur en PRO illimité
     */
    #[Route('/users/{id}/lifetime-pro', name: 'app_admin_lifetime_pro', methods: ['PUT'])]
    public function setLifetimePro(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $startDate = new \DateTimeImmutable();
        
        $user->setAccountType('PRO');
        $user->setIsLifetimePro(true);
        $user->setSubscriptionExpiresAt(null);
        $user->setSubscriptionStartDate($startDate);
        $user->setUpdatedAt(new \DateTime());

        // Créer ou mettre à jour l'objet Subscription pour la cohérence
        $subscriptionRepo = $this->entityManager->getRepository(\App\Entity\Subscription::class);
        $subscription = $subscriptionRepo->findOneBy(['user' => $user]);
        
        if (!$subscription) {
            $subscription = new \App\Entity\Subscription();
            $subscription->setUser($user);
            $subscription->setStartDate($startDate);
            $subscription->setCreatedAt($startDate);
            $this->entityManager->persist($subscription);
        }
        
        $subscription->setAccountType('PRO');
        $subscription->setStatus('lifetime');
        $subscription->setExpiresAt(null);
        $subscription->setUpdatedAt($startDate);

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Utilisateur mis en PRO illimité',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFullName(),
                'accountType' => 'PRO',
                'isPro' => true,
                'isLifetimePro' => true,
                'subscriptionExpiresAt' => null,
                'subscriptionStartDate' => $startDate->format('c')
            ]
        ]);
    }

    /**
     * Retirer le PRO illimité
     */
    #[Route('/users/{id}/remove-lifetime-pro', name: 'app_admin_remove_lifetime_pro', methods: ['PUT'])]
    public function removeLifetimePro(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $user->setIsLifetimePro(false);
        $user->setAccountType('FREE');
        $user->setSubscriptionExpiresAt(null);
        $user->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $this->json([
            'message' => 'PRO illimité retiré',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'accountType' => 'FREE',
                'isLifetimePro' => false
            ]
        ]);
    }

    /**
     * SÉCURITÉ — Promouvoir un utilisateur en ROLE_ADMIN.
     *
     * Seul un ROLE_ADMIN peut appeler cet endpoint (double protection : access_control + #[IsGranted]).
     * OWASP A01 : le rôle est assigné côté serveur uniquement, jamais depuis une requête JSON.
     *
     * PUT /api/v1/admin/users/{id}/promote-admin
     */
    #[Route('/users/{id}/promote-admin', name: 'app_admin_promote_admin', methods: ['PUT'])]
    public function promoteAdmin(int $id, Request $request): JsonResponse
    {
        /** @var \App\Entity\User $currentAdmin */
        $currentAdmin = $this->getUser();

        $user = $this->userRepository->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Idempotent : déjà admin, pas d'action nécessaire
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json([
                'message' => 'Cet utilisateur est déjà administrateur.',
                'roles' => $user->getRoles(),
            ]);
        }

        // Assigner ROLE_ADMIN — côté serveur uniquement, jamais depuis JSON
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $this->entityManager->flush();

        // AUDIT : toute promotion admin est tracée (OWASP A09)
        $this->securityLogger->logAdminAction($currentAdmin, 'promote_admin', $request, [
            'target_user_id'    => $user->getId(),
            'target_user_email' => $user->getEmail(),
        ]);

        return $this->json([
            'message' => 'Utilisateur promu administrateur.',
            'user' => [
                'id'    => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }

    /**
     * SÉCURITÉ — Rétrograder un admin (retirer ROLE_ADMIN).
     *
     * PROTECTION CRITIQUE : garantit qu'il reste au moins 1 admin dans le système.
     * Un admin ne peut pas se rétrograder lui-même via cet endpoint.
     *
     * PUT /api/v1/admin/users/{id}/demote-admin
     */
    #[Route('/users/{id}/demote-admin', name: 'app_admin_demote_admin', methods: ['PUT'])]
    public function demoteAdmin(int $id, Request $request): JsonResponse
    {
        /** @var \App\Entity\User $currentAdmin */
        $currentAdmin = $this->getUser();

        $user = $this->userRepository->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Empêcher l'auto-rétrogradation (un admin ne peut pas se retirer lui-même ROLE_ADMIN)
        if ($currentAdmin->getId() === $user->getId()) {
            return $this->json([
                'error' => 'Vous ne pouvez pas vous rétrograder vous-même.',
            ], 403);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['message' => "Cet utilisateur n'est pas admin."]);
        }

        // PROTECTION DERNIER ADMIN : empêche de supprimer le seul admin du système
        $adminCount = $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from('App\Entity\User', 'u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()
            ->getSingleScalarResult();

        if ((int) $adminCount <= 1) {
            return $this->json([
                'error' => 'Impossible de rétrograder le dernier administrateur du système.',
                'detail' => 'Créez un autre administrateur avant de retirer ce rôle.',
            ], 403);
        }

        // Retirer ROLE_ADMIN, conserver ROLE_USER
        $user->setRoles(['ROLE_USER']);
        $this->entityManager->flush();

        // AUDIT : toute rétrogradation admin est tracée (OWASP A09)
        $this->securityLogger->logAdminAction($currentAdmin, 'demote_admin', $request, [
            'target_user_id'    => $user->getId(),
            'target_user_email' => $user->getEmail(),
        ]);

        return $this->json([
            'message' => 'Rôle admin retiré.',
            'user' => [
                'id'    => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }

    /**
     * Liste toutes les annonces (avec filtres)
     */
    #[Route('/listings', name: 'app_admin_listings', methods: ['GET'])]
    public function getListings(Request $request): JsonResponse
    {
        $limit = min($request->query->get('limit', 50), 100);
        $offset = $request->query->get('offset', 0);
        $status = $request->query->get('status');
        $category = $request->query->get('category');

        $qb = $this->listingRepository->createQueryBuilder('l')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('l.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('l.status = :status')
                ->setParameter('status', $status);
        }

        if ($category) {
            $qb->andWhere('l.category = :category')
                ->setParameter('category', $category);
        }

        $listings = $qb->getQuery()->getResult();

        // Compter le total
        $countQb = clone $qb;
        $countQb->select('COUNT(l.id)');
        $countQb->resetDQLPart('orderBy');
        $countQb->setFirstResult(0)->setMaxResults(null);
        $total = $countQb->getQuery()->getSingleScalarResult();

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
                'country' => $listing->getCountry(),
                'isFeatured' => $listing->isFeatured(),
                'viewsCount' => $listing->getViewsCount(),
                'contactsCount' => $listing->getContactsCount(),
                'createdAt' => $listing->getCreatedAt()->format('c'),
                'expiresAt' => $listing->getExpiresAt()->format('c'),
                'user' => [
                    'id' => $listing->getUser()->getId(),
                    'email' => $listing->getUser()->getEmail(),
                    'accountType' => $listing->getUser()->getAccountType()
                ]
            ];
        }, $listings);

        return $this->json([
            'listings' => $data,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Supprimer une annonce (modération)
     */
    #[Route('/listings/{id}', name: 'app_admin_delete_listing', methods: ['DELETE'])]
    public function deleteListing(int $id): JsonResponse
    {
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        $this->entityManager->remove($listing);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Annonce supprimée avec succès'
        ]);
    }

    /**
     * Approuver une annonce (changer son statut en active)
     */
    #[Route('/listings/{id}/approve', name: 'app_admin_approve_listing', methods: ['PUT'])]
    public function approveListing(int $id): JsonResponse
    {
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        $listing->setStatus('active');
        $listing->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Annonce approuvée avec succès',
            'data' => [
                'id' => $listing->getId(),
                'status' => 'active'
            ]
        ]);
    }

    /**
     * Revenus totaux
     */
    #[Route('/revenues', name: 'app_admin_revenues', methods: ['GET'])]
    public function getRevenues(): JsonResponse
    {
        // Revenus totaux
        $qb = $this->entityManager->createQueryBuilder();
        $totalRevenue = $qb->select('SUM(p.amount)')
            ->from('App\Entity\Payment', 'p')
            ->where('p.status = :completed')
            ->andWhere('p.currency = :xof')
            ->setParameter('completed', 'completed')
            ->setParameter('xof', 'XOF')
            ->getQuery()
            ->getSingleScalarResult();

        // Revenus par type (PostgreSQL syntax)
        $conn = $this->entityManager->getConnection();
        $sql = "SELECT 
                    p.metadata->>'type' as type,
                    SUM(p.amount) as amount,
                    COUNT(p.id) as count
                FROM payments p
                WHERE p.status = :completed
                GROUP BY p.metadata->>'type'";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['completed' => 'completed']);
        $revenuesByType = $result->fetchAllAssociative();

        return $this->json([
            'revenues' => [
                'total' => (float) ($totalRevenue ?? 0),
                'currency' => 'XOF',
                'byType' => $revenuesByType
            ]
        ]);
    }

    /**
     * Revenus par mois
     */
    #[Route('/revenues/monthly', name: 'app_admin_revenues_monthly', methods: ['GET'])]
    public function getRevenuesMonthly(): JsonResponse
    {
        // Revenus des 12 derniers mois (PostgreSQL syntax)
        $conn = $this->entityManager->getConnection();
        $sql = "SELECT 
                    TO_CHAR(p.created_at, 'YYYY-MM') as month,
                    SUM(p.amount) as amount,
                    COUNT(p.id) as count
                FROM payments p
                WHERE p.status = :completed
                AND p.created_at >= :twelveMonthsAgo
                GROUP BY TO_CHAR(p.created_at, 'YYYY-MM')
                ORDER BY month ASC";
        
        $stmt = $conn->prepare($sql);
        $twelveMonthsAgo = (new \DateTimeImmutable('-12 months'))->format('Y-m-d H:i:s');
        $result = $stmt->executeQuery([
            'completed' => 'completed',
            'twelveMonthsAgo' => $twelveMonthsAgo
        ]);
        $results = $result->fetchAllAssociative();

        return $this->json([
            'revenues' => [
                'monthly' => $results,
                'currency' => 'XOF'
            ]
        ]);
    }

    /**
     * Statistiques de croissance
     */
    #[Route('/stats/growth', name: 'app_admin_stats_growth', methods: ['GET'])]
    public function getGrowthStats(): JsonResponse
    {
        // Nouveaux utilisateurs par jour (30 derniers jours) - PostgreSQL
        $conn = $this->entityManager->getConnection();
        $thirtyDaysAgo = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        
        $sqlUsers = "SELECT 
                        CAST(u.created_at AS DATE) as date,
                        COUNT(u.id) as count
                    FROM users u
                    WHERE u.created_at >= :thirtyDaysAgo
                    GROUP BY CAST(u.created_at AS DATE)
                    ORDER BY date ASC";
        
        $stmt = $conn->prepare($sqlUsers);
        $result = $stmt->executeQuery(['thirtyDaysAgo' => $thirtyDaysAgo]);
        $usersByDay = $result->fetchAllAssociative();

        // Nouvelles annonces par jour (30 derniers jours)
        $sqlListings = "SELECT 
                           CAST(l.created_at AS DATE) as date,
                           COUNT(l.id) as count
                       FROM listings l
                       WHERE l.created_at >= :thirtyDaysAgo
                       GROUP BY CAST(l.created_at AS DATE)
                       ORDER BY date ASC";
        
        $stmt = $conn->prepare($sqlListings);
        $result = $stmt->executeQuery(['thirtyDaysAgo' => $thirtyDaysAgo]);
        $listingsByDay = $result->fetchAllAssociative();

        return $this->json([
            'growth' => [
                'usersByDay' => $usersByDay,
                'listingsByDay' => $listingsByDay
            ]
        ]);
    }

    /**
     * Audit complet : Vérification de l'intégrité des données
     * - Liste tous les utilisateurs avec leurs statuts
     * - Vérifie que chaque annonce appartient au bon utilisateur
     * - Détecte les anomalies
     */
    #[Route('/audit', name: 'app_admin_audit', methods: ['GET'])]
    public function auditData(): JsonResponse
    {
        $anomalies = [];
        
        // 1. Récupérer tous les utilisateurs avec leurs stats
        $users = $this->userRepository->findAll();
        $usersData = [];
        
        foreach ($users as $user) {
            $listings = $user->getListings();
            $listingsData = [];
            
            foreach ($listings as $listing) {
                // Vérifier l'intégrité : l'annonce appartient-elle vraiment à cet utilisateur ?
                if ($listing->getUser()->getId() !== $user->getId()) {
                    $anomalies[] = [
                        'type' => 'LISTING_OWNER_MISMATCH',
                        'severity' => 'CRITICAL',
                        'message' => "L'annonce #{$listing->getId()} est liée à l'utilisateur #{$user->getId()} mais getUser() retourne #{$listing->getUser()->getId()}",
                        'listingId' => $listing->getId(),
                        'expectedUserId' => $user->getId(),
                        'actualUserId' => $listing->getUser()->getId()
                    ];
                }
                
                $listingsData[] = [
                    'id' => $listing->getId(),
                    'title' => $listing->getTitle(),
                    'status' => $listing->getStatus(),
                    'createdAt' => $listing->getCreatedAt()->format('Y-m-d H:i:s'),
                    'expiresAt' => $listing->getExpiresAt()?->format('Y-m-d H:i:s'),
                    'ownerId' => $listing->getUser()->getId()
                ];
            }
            
            // Vérifier cohérence du statut PRO
            $isPro = $user->isPro();
            $accountType = $user->getAccountType();
            $isLifetimePro = $user->isLifetimePro();
            $subscriptionExpires = $user->getSubscriptionExpiresAt();
            
            $proStatus = 'OK';
            if ($accountType === 'PRO' && !$isPro && !$isLifetimePro && (!$subscriptionExpires || $subscriptionExpires < new \DateTime())) {
                $proStatus = 'EXPIRED';
            }
            if ($isLifetimePro && $accountType !== 'PRO') {
                $anomalies[] = [
                    'type' => 'PRO_STATUS_MISMATCH',
                    'severity' => 'WARNING',
                    'message' => "Utilisateur #{$user->getId()} est lifetimePro mais accountType est {$accountType}",
                    'userId' => $user->getId()
                ];
            }
            
            $usersData[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFullName(),
                'phone' => $user->getPhone(),
                'accountType' => $accountType,
                'isPro' => $isPro,
                'isLifetimePro' => $isLifetimePro,
                'subscriptionExpiresAt' => $subscriptionExpires?->format('Y-m-d H:i:s'),
                'proStatus' => $proStatus,
                'createdAt' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
                'totalListings' => count($listingsData),
                'listingsByStatus' => [
                    'active' => count(array_filter($listingsData, fn($l) => $l['status'] === 'active')),
                    'draft' => count(array_filter($listingsData, fn($l) => $l['status'] === 'draft')),
                    'expired' => count(array_filter($listingsData, fn($l) => $l['status'] === 'expired')),
                    'sold' => count(array_filter($listingsData, fn($l) => $l['status'] === 'sold')),
                    'suspended' => count(array_filter($listingsData, fn($l) => $l['status'] === 'suspended'))
                ],
                'listings' => $listingsData
            ];
        }
        
        // 2. Vérifier les annonces orphelines (sans utilisateur valide)
        $allListings = $this->listingRepository->findAll();
        foreach ($allListings as $listing) {
            if (!$listing->getUser()) {
                $anomalies[] = [
                    'type' => 'ORPHAN_LISTING',
                    'severity' => 'CRITICAL',
                    'message' => "L'annonce #{$listing->getId()} n'a pas d'utilisateur associé",
                    'listingId' => $listing->getId()
                ];
            }
        }
        
        // 3. Statistiques globales
        $stats = [
            'totalUsers' => count($users),
            'usersByAccountType' => [
                'FREE' => count(array_filter($usersData, fn($u) => $u['accountType'] === 'FREE')),
                'PRO' => count(array_filter($usersData, fn($u) => $u['accountType'] === 'PRO'))
            ],
            'proUsers' => [
                'lifetime' => count(array_filter($usersData, fn($u) => $u['isLifetimePro'])),
                'subscription' => count(array_filter($usersData, fn($u) => $u['isPro'] && !$u['isLifetimePro'])),
                'expired' => count(array_filter($usersData, fn($u) => $u['proStatus'] === 'EXPIRED'))
            ],
            'totalListings' => count($allListings),
            'listingsByStatus' => [
                'active' => $this->listingRepository->count(['status' => 'active']),
                'draft' => $this->listingRepository->count(['status' => 'draft']),
                'expired' => $this->listingRepository->count(['status' => 'expired']),
                'sold' => $this->listingRepository->count(['status' => 'sold']),
                'suspended' => $this->listingRepository->count(['status' => 'suspended'])
            ],
            'totalAnomalies' => count($anomalies),
            'anomaliesBySeverity' => [
                'CRITICAL' => count(array_filter($anomalies, fn($a) => $a['severity'] === 'CRITICAL')),
                'WARNING' => count(array_filter($anomalies, fn($a) => $a['severity'] === 'WARNING'))
            ]
        ];
        
        return $this->json([
            'audit' => [
                'generatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                'stats' => $stats,
                'anomalies' => $anomalies,
                'users' => $usersData
            ]
        ]);
    }

    /**
     * Résumé rapide des utilisateurs et leurs comptes
     */
    #[Route('/audit/summary', name: 'app_admin_audit_summary', methods: ['GET'])]
    public function auditSummary(): JsonResponse
    {
        $users = $this->userRepository->findAll();
        
        $summary = [];
        foreach ($users as $user) {
            $listings = $user->getListings();
            
            $summary[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFullName(),
                'accountType' => $user->getAccountType(),
                'isPro' => $user->isPro(),
                'isLifetimePro' => $user->isLifetimePro(),
                'subscriptionExpiresAt' => $user->getSubscriptionExpiresAt()?->format('Y-m-d'),
                'createdAt' => $user->getCreatedAt()->format('Y-m-d'),
                'activeListings' => $listings->filter(fn($l) => $l->getStatus() === 'active')->count(),
                'totalListings' => $listings->count()
            ];
        }
        
        // Trier par date de création (plus récent d'abord)
        usort($summary, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        
        return $this->json([
            'totalUsers' => count($summary),
            'freeUsers' => count(array_filter($summary, fn($u) => $u['accountType'] === 'FREE')),
            'proUsers' => count(array_filter($summary, fn($u) => $u['accountType'] === 'PRO')),
            'users' => $summary
        ]);
    }

    /**
     * Liste des paiements en attente de vérification
     * Pour les paiements Wave Link sans API
     */
    #[Route('/payments/pending', name: 'app_admin_pending_payments', methods: ['GET'])]
    public function getPendingPayments(Request $request): JsonResponse
    {
        $limit = min($request->query->get('limit', 50), 100);
        $offset = $request->query->get('offset', 0);

        $qb = $this->paymentRepository->createQueryBuilder('p')
            ->where('p.status = :pending OR p.status = :pendingVerification')
            ->setParameter('pending', 'pending')
            ->setParameter('pendingVerification', 'pending_verification')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('p.createdAt', 'DESC');

        $payments = $qb->getQuery()->getResult();

        $data = array_map(function($payment) {
            $user = $payment->getUser();
            $metadata = $payment->getMetadata() ?? [];
            return [
                'id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'status' => $payment->getStatus(),
                'description' => $payment->getDescription(),
                'paymentMethod' => $payment->getPaymentMethod(),
                'createdAt' => $payment->getCreatedAt()->format('c'),
                'metadata' => $metadata,
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'phone' => $user->getPhone(),
                    'fullName' => $user->getFullName(),
                    'accountType' => $user->getAccountType()
                ]
            ];
        }, $payments);

        // Compter le total
        $countQb = $this->paymentRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :pending OR p.status = :pendingVerification')
            ->setParameter('pending', 'pending')
            ->setParameter('pendingVerification', 'pending_verification');
        $total = $countQb->getQuery()->getSingleScalarResult();

        return $this->json([
            'payments' => $data,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Confirmer un paiement et activer le PRO
     */
    #[Route('/payments/{id}/confirm', name: 'app_admin_confirm_payment', methods: ['PUT'])]
    public function confirmPayment(int $id): JsonResponse
    {
        $payment = $this->paymentRepository->find($id);

        if (!$payment) {
            return $this->json(['error' => 'Paiement non trouvé'], 404);
        }

        $user = $payment->getUser();
        $metadata = $payment->getMetadata() ?? [];
        $months = $metadata['months'] ?? 1;
        $durationDays = $months * 30;

        // Calculer la date d'expiration
        $startDate = new \DateTimeImmutable();
        $currentExpiry = $user->getSubscriptionExpiresAt();
        if ($currentExpiry && $currentExpiry > $startDate) {
            $expiresAt = $currentExpiry->modify("+{$durationDays} days");
        } else {
            $expiresAt = $startDate->modify("+{$durationDays} days");
        }

        // Mettre à jour le paiement
        $payment->setStatus('completed');
        $payment->setCompletedAt(new \DateTimeImmutable());

        // Mettre à jour l'utilisateur
        $user->setAccountType('PRO');
        $user->setSubscriptionExpiresAt($expiresAt);
        $user->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Paiement confirmé et compte PRO activé',
            'payment' => [
                'id' => $payment->getId(),
                'status' => 'completed',
                'amount' => $payment->getAmount()
            ],
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFullName(),
                'accountType' => 'PRO',
                'subscriptionExpiresAt' => $expiresAt->format('c')
            ]
        ]);
    }

    /**
     * Rejeter un paiement
     */
    #[Route('/payments/{id}/reject', name: 'app_admin_reject_payment', methods: ['PUT'])]
    public function rejectPayment(int $id, Request $request): JsonResponse
    {
        $payment = $this->paymentRepository->find($id);

        if (!$payment) {
            return $this->json(['error' => 'Paiement non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Paiement non reçu';

        $payment->setStatus('rejected');
        $payment->setErrorMessage($reason);

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Paiement rejeté',
            'payment' => [
                'id' => $payment->getId(),
                'status' => 'rejected',
                'reason' => $reason
            ]
        ]);
    }

    /**
     * Activer manuellement le PRO pour un utilisateur (sans paiement)
     */
    #[Route('/users/{id}/activate-pro', name: 'app_admin_activate_pro', methods: ['PUT'])]
    public function activatePro(int $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $months = $data['months'] ?? 1;
        $durationDays = $months * 30;

        // Calculer la date d'expiration
        $startDate = new \DateTimeImmutable();
        $currentExpiry = $user->getSubscriptionExpiresAt();
        if ($currentExpiry && $currentExpiry > $startDate) {
            $expiresAt = $currentExpiry->modify("+{$durationDays} days");
        } else {
            $expiresAt = $startDate->modify("+{$durationDays} days");
            // Définir la date de début seulement si c'est une nouvelle activation
            $user->setSubscriptionStartDate($startDate);
        }

        $user->setAccountType('PRO');
        $user->setSubscriptionExpiresAt($expiresAt);
        $user->setUpdatedAt(new \DateTime());

        // Créer ou mettre à jour l'objet Subscription pour la cohérence
        $subscriptionRepo = $this->entityManager->getRepository(\App\Entity\Subscription::class);
        $subscription = $subscriptionRepo->findOneBy(['user' => $user]);
        
        if (!$subscription) {
            $subscription = new \App\Entity\Subscription();
            $subscription->setUser($user);
            $subscription->setAccountType('PRO');
            $subscription->setStartDate($startDate);
            $subscription->setCreatedAt($startDate);
            $this->entityManager->persist($subscription);
        }
        
        $subscription->setStatus('active');
        $subscription->setExpiresAt($expiresAt);
        $subscription->setUpdatedAt($startDate);

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => "Compte PRO activé pour {$months} mois",
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFullName(),
                'accountType' => 'PRO',
                'isPro' => true,
                'subscriptionExpiresAt' => $expiresAt->format('c'),
                'subscriptionStartDate' => $user->getSubscriptionStartDate()?->format('c')
            ]
        ]);
    }

    /**
     * Désactiver le PRO d'un utilisateur
     */
    #[Route('/users/{id}/deactivate-pro', name: 'app_admin_deactivate_pro', methods: ['PUT'])]
    public function deactivatePro(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        $user->setAccountType('FREE');
        $user->setSubscriptionExpiresAt(null);
        $user->setIsLifetimePro(false);
        $user->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Compte PRO désactivé',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFullName(),
                'accountType' => 'FREE'
            ]
        ]);
    }

    /**
     * Liste tous les paiements
     */
    #[Route('/payments', name: 'app_admin_all_payments', methods: ['GET'])]
    public function getAllPayments(Request $request): JsonResponse
    {
        $limit = min($request->query->get('limit', 50), 100);
        $offset = $request->query->get('offset', 0);
        $status = $request->query->get('status');

        $qb = $this->paymentRepository->createQueryBuilder('p')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('p.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', $status);
        }

        $payments = $qb->getQuery()->getResult();

        $data = array_map(function($payment) {
            $user = $payment->getUser();
            return [
                'id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'status' => $payment->getStatus(),
                'description' => $payment->getDescription(),
                'paymentMethod' => $payment->getPaymentMethod(),
                'createdAt' => $payment->getCreatedAt()->format('c'),
                'completedAt' => $payment->getCompletedAt()?->format('c'),
                'metadata' => $payment->getMetadata(),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'phone' => $user->getPhone(),
                    'fullName' => $user->getFullName()
                ]
            ];
        }, $payments);

        // Compter le total
        $countQb = $this->paymentRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)');
        if ($status) {
            $countQb->andWhere('p.status = :status')
                ->setParameter('status', $status);
        }
        $total = $countQb->getQuery()->getSingleScalarResult();

        return $this->json([
            'payments' => $data,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Demandes de paiement avec preuves (captures d'écran)
     * Les utilisateurs peuvent soumettre leur preuve de paiement
     */
    #[Route('/payment-requests', name: 'app_admin_payment_requests', methods: ['GET'])]
    public function getPaymentRequests(Request $request): JsonResponse
    {
        $limit = min($request->query->get('limit', 50), 100);
        $offset = $request->query->get('offset', 0);

        // Chercher les paiements avec preuve (metadata contient 'proof')
        $qb = $this->paymentRepository->createQueryBuilder('p')
            ->where('p.status = :pending OR p.status = :pendingVerification')
            ->setParameter('pending', 'pending')
            ->setParameter('pendingVerification', 'pending_verification')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('p.createdAt', 'DESC');

        $payments = $qb->getQuery()->getResult();

        $data = array_map(function($payment) {
            $user = $payment->getUser();
            $metadata = $payment->getMetadata() ?? [];
            return [
                'id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'status' => $payment->getStatus(),
                'description' => $payment->getDescription(),
                'paymentMethod' => $payment->getPaymentMethod(),
                'months' => $metadata['months'] ?? 1,
                'proofImage' => $metadata['proofImage'] ?? null,
                'phone' => $metadata['phone'] ?? null,
                'createdAt' => $payment->getCreatedAt()->format('c'),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'phone' => $user->getPhone(),
                    'fullName' => $user->getFullName(),
                    'accountType' => $user->getAccountType(),
                    'profilePicture' => $user->getProfilePicture()
                ]
            ];
        }, $payments);

        return $this->json([
            'requests' => $data,
            'total' => count($data)
        ]);
    }

    /**
     * Supprimer un utilisateur et toutes ses données
     * DELETE /api/v1/admin/users/{id}
     */
    #[Route('/users/{id}', name: 'app_admin_delete_user', methods: ['DELETE'])]
    public function deleteUser(int $id, Request $request): JsonResponse
    {
        /** @var \App\Entity\User $currentAdmin */
        $currentAdmin = $this->getUser();

        $user = $this->userRepository->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Empêcher l'auto-suppression
        if ($currentAdmin->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas supprimer votre propre compte.'], 403);
        }

        // Empêcher suppression d'un admin
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['error' => 'Impossible de supprimer un administrateur. Retirez d\'abord le rôle admin.'], 403);
        }

        $userEmail = $user->getEmail();
        $userId = $user->getId();
        $conn = $this->entityManager->getConnection();

        try {
            $conn->beginTransaction();

            // 1. Tables enfant des listings de l'utilisateur
            $listingIds = $conn->fetchFirstColumn('SELECT id FROM listings WHERE user_id = :uid', ['uid' => $userId]);
            if (!empty($listingIds)) {
                $in = implode(',', array_map('intval', $listingIds));
                $conn->executeStatement("DELETE FROM availability_calendar WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM listing_views WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM rooms WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM images WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM reports WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM reviews WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM secure_deposits WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM visit_slots WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM favorites WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM offers WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM bookings WHERE listing_id IN ({$in})");
                $conn->executeStatement("DELETE FROM conversations WHERE listing_id IN ({$in})");
            }

            // 2. Tables avec FK directe vers users (colonne unique)
            $conn->executeStatement('DELETE FROM notification WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM notification_preference WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM favorites WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM messages WHERE sender_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM booking_payments WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM contract_audit_logs WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM contact_messages WHERE responded_by_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM events WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM images WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM two_factor_codes WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM user_documents WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM verification_request WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM moderation_actions WHERE moderator_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM push_subscriptions WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM refresh_tokens WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM security_logs WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM ticket_purchases WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM payment_reminders WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM operations WHERE user_id = :uid OR provider_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM review_stats WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM scope_verifications WHERE user_id = :uid OR reviewed_by_id = :uid', ['uid' => $userId]);

            // 3. Tables avec 2 FK vers users
            $conn->executeStatement('DELETE FROM deposit_disputes WHERE reported_by_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM offers WHERE buyer_id = :uid OR seller_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM conversations WHERE seller_id = :uid OR buyer_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM bookings WHERE tenant_id = :uid OR owner_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM orders WHERE client_id = :uid OR provider_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM reviews WHERE seller_id = :uid OR reviewer_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM secure_deposits WHERE landlord_id = :uid OR tenant_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM visit_slots WHERE owner_id = :uid OR booked_by_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM reports WHERE reporter_id = :uid', ['uid' => $userId]);

            // 4. Listings
            $conn->executeStatement('DELETE FROM listings WHERE user_id = :uid', ['uid' => $userId]);

            // 5. Subscriptions & Payments
            $conn->executeStatement('DELETE FROM subscriptions WHERE user_id = :uid', ['uid' => $userId]);
            $conn->executeStatement('DELETE FROM payments WHERE user_id = :uid', ['uid' => $userId]);

            // 6. Supprimer l'utilisateur
            $conn->executeStatement('DELETE FROM users WHERE id = :uid', ['uid' => $userId]);

            $conn->commit();

            $this->securityLogger->logAdminAction($currentAdmin, 'delete_user', $request, [
                'deleted_user_id' => $userId,
                'deleted_user_email' => $userEmail,
            ]);

            return $this->json(['message' => 'Utilisateur et toutes ses données supprimés.']);
        } catch (\Exception $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            return $this->json(['error' => 'Erreur lors de la suppression : ' . $e->getMessage()], 500);
        }
    }
}
