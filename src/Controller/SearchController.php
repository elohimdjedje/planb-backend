<?php

namespace App\Controller;

use App\Repository\ListingRepository;
use App\Service\IntelligentSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/search')]
class SearchController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ListingRepository $listingRepository,
        private IntelligentSearchService $intelligentSearchService
    ) {}

    /**
     * Recherche avancée d'annonces
     */
    #[Route('', name: 'app_search_listings', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        // Paramètres de recherche
        $query = $request->query->get('q', ''); // Mot-clé
        $category = $request->query->get('category');
        $type = $request->query->get('type'); // vente, location, recherche
        $country = $request->query->get('country');
        $city = $request->query->get('city');
        $minPrice = $request->query->get('minPrice');
        $maxPrice = $request->query->get('maxPrice');
        $currency = $request->query->get('currency', 'XOF');
        $sortBy = $request->query->get('sortBy', 'recent'); // recent, price_asc, price_desc, popular
        $limit = min($request->query->get('limit', 20), 100);
        $offset = $request->query->get('offset', 0);

        // Construction de la requête
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable());

        // Recherche intelligente si query fourni
        $useIntelligentSearch = $request->query->get('intelligent', 'true') === 'true';
        
        if ($query && $useIntelligentSearch) {
            // Utiliser le service de recherche intelligente
            $filters = [
                'category' => $category,
                'type' => $type,
                'country' => $country,
                'city' => $city,
                'minPrice' => $minPrice,
                'maxPrice' => $maxPrice,
            ];
            
            $intelligentResults = $this->intelligentSearchService->intelligentSearch(
                $query,
                array_filter($filters),
                $limit,
                $offset
            );
            
            // Formater les résultats avec scores
            $data = array_map(function($result) {
                $listing = $result['listing'];
                $images = $listing->getImages()->toArray();
                
                return [
                    'id' => $listing->getId(),
                    'title' => $listing->getTitle(),
                    'description' => substr($listing->getDescription(), 0, 150) . '...',
                    'price' => $listing->getPrice(),
                    'currency' => $listing->getCurrency(),
                    'category' => $listing->getCategory(),
                    'subcategory' => $listing->getSubcategory(),
                    'type' => $listing->getType(),
                    'country' => $listing->getCountry(),
                    'city' => $listing->getCity(),
                    'status' => $listing->getStatus(),
                    'isFeatured' => $listing->isFeatured(),
                    'viewsCount' => $listing->getViewsCount(),
                    'mainImage' => !empty($images) ? $images[0]->getUrl() : null,
                    'imagesCount' => count($images),
                    'createdAt' => $listing->getCreatedAt()->format('c'),
                    'expiresAt' => $listing->getExpiresAt()->format('c'),
                    'relevance' => [
                        'score' => $result['score'],
                        'label' => $result['relevance']
                    ]
                ];
            }, $intelligentResults);
            
            // Compter le total (approximatif pour la recherche intelligente)
            $total = count($intelligentResults);
            
            return $this->json([
                'results' => $data,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'hasMore' => count($intelligentResults) >= $limit,
                'intelligent' => true
            ]);
        }
        
        // Recherche basique (fallback)
        if ($query) {
            $qb->andWhere('LOWER(l.title) LIKE :query OR LOWER(l.description) LIKE :query')
                ->setParameter('query', '%' . strtolower($query) . '%');
        }

        // Filtres
        if ($category) {
            $qb->andWhere('l.category = :category')
                ->setParameter('category', $category);
        }

        if ($type) {
            $qb->andWhere('l.type = :type')
                ->setParameter('type', $type);
        }

        if ($country) {
            $qb->andWhere('l.country = :country')
                ->setParameter('country', $country);
        }

        if ($city) {
            $qb->andWhere('LOWER(l.city) LIKE :city')
                ->setParameter('city', '%' . strtolower($city) . '%');
        }

        // Filtre par devise (une seule fois, avant les filtres de prix)
        if (($minPrice !== null || $maxPrice !== null) && $currency) {
            $qb->andWhere('l.currency = :currency')
                ->setParameter('currency', $currency);
        }

        if ($minPrice !== null) {
            $qb->andWhere('l.price >= :minPrice')
                ->setParameter('minPrice', $minPrice);
        }

        if ($maxPrice !== null) {
            $qb->andWhere('l.price <= :maxPrice')
                ->setParameter('maxPrice', $maxPrice);
        }

        // Tri
        switch ($sortBy) {
            case 'price_asc':
                $qb->orderBy('l.price', 'ASC');
                break;
            case 'price_desc':
                $qb->orderBy('l.price', 'DESC');
                break;
            case 'popular':
                $qb->orderBy('l.viewsCount', 'DESC');
                break;
            case 'recent':
            default:
                $qb->orderBy('l.createdAt', 'DESC');
                break;
        }

        // Annonces en vedette d'abord
        $qb->addOrderBy('l.isFeatured', 'DESC');

        // Pagination
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        $listings = $qb->getQuery()->getResult();

        // Compter le total
        $countQb = clone $qb;
        $countQb->select('COUNT(l.id)');
        $countQb->resetDQLPart('orderBy'); // Supprimer ORDER BY pour le COUNT
        $countQb->setFirstResult(0)->setMaxResults(null);
        $total = $countQb->getQuery()->getSingleScalarResult();

        // Formater les résultats
        $data = array_map(function($listing) {
            $images = $listing->getImages()->toArray();
            
            return [
                'id' => $listing->getId(),
                'title' => $listing->getTitle(),
                'description' => substr($listing->getDescription(), 0, 150) . '...',
                'price' => $listing->getPrice(),
                'currency' => $listing->getCurrency(),
                'category' => $listing->getCategory(),
                'subcategory' => $listing->getSubcategory(),
                'type' => $listing->getType(),
                'country' => $listing->getCountry(),
                'city' => $listing->getCity(),
                'status' => $listing->getStatus(),
                'isFeatured' => $listing->isFeatured(),
                'viewsCount' => $listing->getViewsCount(),
                'mainImage' => !empty($images) ? $images[0]->getUrl() : null,
                'imagesCount' => count($images),
                'createdAt' => $listing->getCreatedAt()->format('c'),
                'expiresAt' => $listing->getExpiresAt()->format('c'),
            ];
        }, $listings);

        return $this->json([
            'results' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total,
            'intelligent' => $useIntelligentSearch && !empty($query)
        ]);
    }

    /**
     * Obtenir les catégories avec compteurs
     */
    #[Route('/categories', name: 'app_search_categories', methods: ['GET'])]
    public function getCategories(): JsonResponse
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l.category', 'COUNT(l.id) as count')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->groupBy('l.category')
            ->orderBy('count', 'DESC');

        $results = $qb->getQuery()->getResult();

        $categories = array_map(function($result) {
            return [
                'name' => $result['category'],
                'count' => (int) $result['count']
            ];
        }, $results);

        return $this->json(['categories' => $categories]);
    }

    /**
     * Obtenir les villes populaires avec compteurs
     */
    #[Route('/cities', name: 'app_search_cities', methods: ['GET'])]
    public function getCities(Request $request): JsonResponse
    {
        $country = $request->query->get('country');

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l.city', 'COUNT(l.id) as count')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable());

        if ($country) {
            $qb->andWhere('l.country = :country')
                ->setParameter('country', $country);
        }

        $qb->groupBy('l.city')
            ->orderBy('count', 'DESC')
            ->setMaxResults(20);

        $results = $qb->getQuery()->getResult();

        $cities = array_map(function($result) {
            return [
                'name' => $result['city'],
                'count' => (int) $result['count']
            ];
        }, $results);

        return $this->json(['cities' => $cities]);
    }

    /**
     * Suggestions de recherche (autocomplete) avec typo-tolérance
     */
    #[Route('/suggestions', name: 'app_search_suggestions', methods: ['GET'])]
    public function getSuggestions(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $fuzzy = $request->query->get('fuzzy', 'true') === 'true';
        
        if (strlen($query) < 2) {
            return $this->json(['suggestions' => []]);
        }

        // Recherche intelligente avec typo-tolérance
        if ($fuzzy && strlen($query) >= 3) {
            $fuzzyResults = $this->intelligentSearchService->fuzzySearch($query);
            if (!empty($fuzzyResults)) {
                $suggestions = array_map(function($result) {
                    return [
                        'text' => $result['suggestion'],
                        'confidence' => round($result['confidence'] * 100),
                        'type' => 'fuzzy'
                    ];
                }, $fuzzyResults);
                
                return $this->json(['suggestions' => $suggestions]);
            }
        }

        // Recherche normale
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT l.title', 'l.category', 'l.type')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->andWhere('LOWER(l.title) LIKE :query')
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->setMaxResults(10);

        $results = $qb->getQuery()->getResult();

        $suggestions = array_map(function($result) {
            return [
                'text' => $result['title'],
                'category' => $result['category'],
                'type' => $result['type'],
                'confidence' => 100,
                'type' => 'exact'
            ];
        }, $results);

        return $this->json(['suggestions' => $suggestions]);
    }

    /**
     * Recherches populaires avec compteur d'annonces réel
     */
    #[Route('/popular', name: 'app_search_popular', methods: ['GET'])]
    public function getPopularSearches(): JsonResponse
    {
        // Définir les recherches populaires avec leurs critères
        $popularSearches = [
            [
                'query' => 'Villa à louer',
                'keywords' => ['villa'],
                'type' => 'location',
                'category' => 'immobilier'
            ],
            [
                'query' => 'Voiture occasion',
                'keywords' => ['voiture', 'auto', 'vehicule'],
                'type' => 'vente',
                'category' => 'vehicule'
            ],
            [
                'query' => 'Appartement Abidjan',
                'keywords' => ['appartement'],
                'city' => 'Abidjan',
                'category' => 'immobilier'
            ],
            [
                'query' => 'Terrain à vendre',
                'keywords' => ['terrain'],
                'type' => 'vente',
                'category' => 'immobilier'
            ],
            [
                'query' => 'Hôtel Assinie',
                'keywords' => ['hôtel', 'hotel'],
                'city' => 'Assinie',
                'category' => 'vacance'
            ],
            [
                'query' => 'Maison moderne',
                'keywords' => ['maison', 'moderne'],
                'category' => 'immobilier'
            ],
            [
                'query' => 'Studio Cocody',
                'keywords' => ['studio'],
                'city' => 'Cocody',
                'category' => 'immobilier'
            ],
            [
                'query' => 'Moto Yamaha',
                'keywords' => ['moto', 'yamaha'],
                'category' => 'vehicule'
            ]
        ];

        $results = [];

        foreach ($popularSearches as $search) {
            // Construire la requête de comptage
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(l.id)')
                ->from('App\Entity\Listing', 'l')
                ->where('l.status = :status')
                ->setParameter('status', 'active')
                ->andWhere('l.expiresAt > :now')
                ->setParameter('now', new \DateTimeImmutable());

            // Recherche par mots-clés dans le titre ou la description
            if (!empty($search['keywords'])) {
                $conditions = [];
                foreach ($search['keywords'] as $index => $keyword) {
                    $conditions[] = "LOWER(l.title) LIKE :keyword{$index} OR LOWER(l.description) LIKE :keyword{$index}";
                    $qb->setParameter("keyword{$index}", '%' . strtolower($keyword) . '%');
                }
                $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
            }

            // Filtres additionnels
            if (!empty($search['category'])) {
                $qb->andWhere('l.category = :category')
                    ->setParameter('category', $search['category']);
            }

            if (!empty($search['type'])) {
                $qb->andWhere('l.type = :type')
                    ->setParameter('type', $search['type']);
            }

            if (!empty($search['city'])) {
                $qb->andWhere('LOWER(l.city) LIKE :city')
                    ->setParameter('city', '%' . strtolower($search['city']) . '%');
            }

            // Compter
            $count = (int) $qb->getQuery()->getSingleScalarResult();

            $results[] = [
                'query' => $search['query'],
                'count' => $count,
                'category' => $search['category'] ?? null,
                'type' => $search['type'] ?? null
            ];
        }

        // Trier par nombre d'annonces décroissant
        usort($results, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        // Limiter aux 5 premières
        $results = array_slice($results, 0, 5);

        return $this->json(['popular' => $results]);
    }

    /**
     * Statistiques de recherche
     */
    #[Route('/stats', name: 'app_search_stats', methods: ['GET'])]
    public function getStats(): JsonResponse
    {
        // Total annonces actives
        $totalActive = $this->listingRepository->count([
            'status' => 'active'
        ]);

        // Par type
        $qb = $this->entityManager->createQueryBuilder();
        $byType = $qb->select('l.type', 'COUNT(l.id) as count')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->groupBy('l.type')
            ->getQuery()
            ->getResult();

        // Par pays
        $qb = $this->entityManager->createQueryBuilder();
        $byCountry = $qb->select('l.country', 'COUNT(l.id) as count')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->groupBy('l.country')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json([
            'stats' => [
                'totalActive' => $totalActive,
                'byType' => $byType,
                'byCountry' => $byCountry
            ]
        ]);
    }
}
