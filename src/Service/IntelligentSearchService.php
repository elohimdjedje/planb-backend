<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de recherche intelligente basique
 * Fonctionnalités:
 * - Typo-tolérance (fuzzy search)
 * - Recherche par synonymes
 * - Scoring de pertinence
 * - Recherche phonétique basique
 */
class IntelligentSearchService
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    // Synonymes courants pour la recherche
    private array $synonyms = [
        'villa' => ['maison', 'résidence', 'domicile'],
        'appartement' => ['appart', 'apt', 'studio', 'logement'],
        'voiture' => ['auto', 'véhicule', 'automobile', 'bagnole'],
        'moto' => ['moto', 'motocyclette', 'scooter'],
        'terrain' => ['parcelle', 'lot', 'superficie'],
        'location' => ['louer', 'loué', 'rental'],
        'vente' => ['vendre', 'vendu', 'sale'],
        'abidjan' => ['abj', 'abidjan'],
        'cocody' => ['cocody'],
        'yopougon' => ['yop', 'yopougon'],
    ];

    // Mots vides (stop words) à ignorer
    private array $stopWords = [
        'le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'à', 'au', 'aux',
        'et', 'ou', 'pour', 'avec', 'sans', 'sur', 'sous', 'dans', 'par',
        'est', 'sont', 'était', 'étaient', 'a', 'ont', 'avoir', 'être'
    ];

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    /**
     * Recherche intelligente avec scoring de pertinence
     * 
     * @param string $query Requête de recherche
     * @param array $filters Filtres additionnels (category, type, city, etc.)
     * @param int $limit Nombre de résultats
     * @param int $offset Offset pour pagination
     * @return array Résultats avec scores de pertinence
     */
    public function intelligentSearch(
        string $query,
        array $filters = [],
        int $limit = 20,
        int $offset = 0
    ): array {
        if (empty(trim($query))) {
            return $this->basicSearch($filters, $limit, $offset);
        }

        // Nettoyer et normaliser la requête
        $normalizedQuery = $this->normalizeQuery($query);
        $keywords = $this->extractKeywords($normalizedQuery);
        
        if (empty($keywords)) {
            return $this->basicSearch($filters, $limit, $offset);
        }

        // Construire la requête avec scoring
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable());

        // Construire les conditions de recherche
        $searchConditions = [];

        foreach ($keywords as $index => $keyword) {
            $paramName = "keyword{$index}";
            $pattern = '%' . strtolower($keyword) . '%';

            // Correspondance dans titre ou description
            $searchConditions[] = "(LOWER(l.title) LIKE :{$paramName} OR LOWER(l.description) LIKE :{$paramName})";
            $qb->setParameter($paramName, $pattern);

            // Recherche par synonymes
            $synonyms = $this->getSynonyms($keyword);
            foreach ($synonyms as $synIndex => $synonym) {
                $synParam = "syn{$index}_{$synIndex}";
                $synPattern = '%' . strtolower($synonym) . '%';
                $searchConditions[] = "(LOWER(l.title) LIKE :{$synParam} OR LOWER(l.description) LIKE :{$synParam})";
                $qb->setParameter($synParam, $synPattern);
            }
        }

        // Combiner toutes les conditions avec OR
        if (!empty($searchConditions)) {
            $qb->andWhere('(' . implode(' OR ', array_unique($searchConditions)) . ')');
        }

        // Appliquer les filtres
        $this->applyFilters($qb, $filters);

        // Trier par date (le scoring sera fait en PHP)
        $qb->orderBy('l.isFeatured', 'DESC')
            ->addOrderBy('l.createdAt', 'DESC');

        // Pagination
        $qb->setFirstResult($offset)
            ->setMaxResults($limit * 2); // Récupérer plus pour scoring

        $results = $qb->getQuery()->getResult();

        // Calculer les scores et formater
        $formattedResults = [];
        foreach ($results as $listing) {
            $score = $this->calculateScore($listing, $keywords);
            
            $formattedResults[] = [
                'listing' => $listing,
                'score' => $score,
                'relevance' => $this->getRelevanceLabel($score)
            ];
        }

        // Trier par score décroissant
        usort($formattedResults, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        // Limiter aux résultats demandés
        return array_slice($formattedResults, 0, $limit);
    }

    /**
     * Recherche basique sans scoring (fallback)
     */
    private function basicSearch(array $filters, int $limit, int $offset): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable());

        $this->applyFilters($qb, $filters);

        $qb->orderBy('l.isFeatured', 'DESC')
            ->addOrderBy('l.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getResult();

        return array_map(function($listing) {
            return [
                'listing' => $listing,
                'score' => 0,
                'relevance' => 'low'
            ];
        }, $results);
    }

    /**
     * Normaliser la requête de recherche
     */
    private function normalizeQuery(string $query): string
    {
        // Convertir en minuscules
        $query = mb_strtolower($query, 'UTF-8');
        
        // Supprimer les caractères spéciaux (garder lettres, chiffres, espaces)
        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query);
        
        // Supprimer les espaces multiples
        $query = preg_replace('/\s+/', ' ', $query);
        
        return trim($query);
    }

    /**
     * Extraire les mots-clés de la requête
     */
    private function extractKeywords(string $query): array
    {
        $words = explode(' ', $query);
        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word);
            
            // Ignorer les mots vides et les mots trop courts
            if (strlen($word) >= 2 && !in_array($word, $this->stopWords)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Obtenir les synonymes d'un mot
     */
    private function getSynonyms(string $word): array
    {
        $word = strtolower($word);
        
        // Chercher dans les synonymes
        foreach ($this->synonyms as $key => $synonyms) {
            if ($key === $word || in_array($word, $synonyms)) {
                return array_merge([$key], $synonyms);
            }
        }

        return [];
    }

    /**
     * Appliquer les filtres à la requête
     */
    private function applyFilters($qb, array $filters): void
    {
        if (!empty($filters['category'])) {
            $qb->andWhere('l.category = :category')
                ->setParameter('category', $filters['category']);
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('l.type = :type')
                ->setParameter('type', $filters['type']);
        }

        if (!empty($filters['country'])) {
            $qb->andWhere('l.country = :country')
                ->setParameter('country', $filters['country']);
        }

        if (!empty($filters['city'])) {
            $qb->andWhere('LOWER(l.city) LIKE :city')
                ->setParameter('city', '%' . strtolower($filters['city']) . '%');
        }

        if (isset($filters['minPrice'])) {
            $qb->andWhere('l.price >= :minPrice')
                ->setParameter('minPrice', $filters['minPrice']);
        }

        if (isset($filters['maxPrice'])) {
            $qb->andWhere('l.price <= :maxPrice')
                ->setParameter('maxPrice', $filters['maxPrice']);
        }
    }

    /**
     * Calculer le score de pertinence pour un listing
     */
    private function calculateScore($listing, array $keywords): int
    {
        $score = 0;
        $title = mb_strtolower($listing->getTitle(), 'UTF-8');
        $description = mb_strtolower($listing->getDescription(), 'UTF-8');

        foreach ($keywords as $keyword) {
            // Correspondance exacte dans le titre
            if ($title === $keyword) {
                $score += 10;
            } elseif (strpos($title, $keyword) !== false) {
                $score += 5;
            }

            // Correspondance dans la description
            if (strpos($description, $keyword) !== false) {
                $score += 2;
            }

            // Bonus pour annonces en vedette
            if ($listing->isFeatured()) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * Obtenir le label de pertinence
     */
    private function getRelevanceLabel(int $score): string
    {
        if ($score >= 15) return 'high';
        if ($score >= 8) return 'medium';
        return 'low';
    }

    /**
     * Recherche avec typo-tolérance (fuzzy search basique)
     * 
     * @param string $query Requête avec possible faute de frappe
     * @return array Suggestions corrigées
     */
    public function fuzzySearch(string $query): array
    {
        $normalized = $this->normalizeQuery($query);
        $keywords = $this->extractKeywords($normalized);

        if (empty($keywords)) {
            return [];
        }

        $suggestions = [];
        
        // Rechercher des titres similaires (distance de Levenshtein)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT l.title')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(50);

        $titles = $qb->getQuery()->getResult();

        foreach ($titles as $titleResult) {
            $title = mb_strtolower($titleResult['title'], 'UTF-8');
            
            foreach ($keywords as $keyword) {
                // Distance de Levenshtein (tolérance: 1-2 caractères)
                $distance = levenshtein($keyword, substr($title, 0, strlen($keyword)));
                
                if ($distance <= 2 && strlen($keyword) >= 3) {
                    $suggestions[] = [
                        'original' => $query,
                        'suggestion' => $titleResult['title'],
                        'confidence' => 1 - ($distance / max(strlen($keyword), strlen($title)))
                    ];
                }
            }
        }

        // Trier par confiance
        usort($suggestions, function($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        return array_slice($suggestions, 0, 5);
    }
}

