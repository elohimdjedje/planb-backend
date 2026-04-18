-- Migration: Création de la table reviews pour le système d'avis
-- Date: 27 Novembre 2024
-- Version: 2.0

-- ============================================================
-- TABLE: reviews
-- Description: Stocke les avis et notes des utilisateurs
-- ============================================================

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    seller_id INT NOT NULL,
    rating SMALLINT NOT NULL,
    comment TEXT NULL,
    review_type VARCHAR(50) NOT NULL DEFAULT 'transaction',
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    
    -- Contraintes
    CONSTRAINT chk_rating_range CHECK (rating >= 1 AND rating <= 5),
    CONSTRAINT chk_review_type CHECK (review_type IN ('vacation', 'transaction')),
    
    -- Clés étrangères
    CONSTRAINT fk_review_listing FOREIGN KEY (listing_id) 
        REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) 
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_seller FOREIGN KEY (seller_id) 
        REFERENCES users(id) ON DELETE CASCADE,
    
    -- Index pour performance
    INDEX idx_review_listing (listing_id),
    INDEX idx_review_reviewer (reviewer_id),
    INDEX idx_review_seller (seller_id),
    INDEX idx_review_created (created_at),
    
    -- Contrainte unique: 1 avis par utilisateur par annonce
    UNIQUE KEY unique_user_listing_review (reviewer_id, listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DONNÉES DE TEST (Optionnel)
-- ============================================================

-- Exemple d'avis pour les tests
INSERT INTO reviews (listing_id, reviewer_id, seller_id, rating, comment, review_type, is_verified, created_at) VALUES
(1, 2, 1, 5, 'Excellent séjour! Villa magnifique et hôte très accueillant.', 'vacation', 1, NOW()),
(2, 3, 1, 4, 'Très bon appartement, bien situé. Quelques petits détails à améliorer.', 'transaction', 1, NOW()),
(3, 2, 4, 5, 'Parfait! Exactement comme décrit.', 'vacation', 1, NOW())
ON DUPLICATE KEY UPDATE rating=rating; -- Évite les erreurs si déjà existant

-- ============================================================
-- VUES UTILES (Optionnel)
-- ============================================================

-- Vue: Statistiques vendeurs
CREATE OR REPLACE VIEW seller_review_stats AS
SELECT 
    seller_id,
    COUNT(*) as total_reviews,
    AVG(rating) as average_rating,
    SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_stars,
    SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_stars,
    SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_stars,
    SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_stars,
    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
FROM reviews
GROUP BY seller_id;

-- Vue: Annonces avec note moyenne
CREATE OR REPLACE VIEW listings_with_ratings AS
SELECT 
    l.*,
    COALESCE(AVG(r.rating), 0) as average_rating,
    COUNT(r.id) as review_count
FROM listings l
LEFT JOIN reviews r ON l.id = r.listing_id
GROUP BY l.id;

-- ============================================================
-- VÉRIFICATION
-- ============================================================

-- Vérifier que la table a été créée
SELECT 'Table reviews créée avec succès!' as status
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
AND table_name = 'reviews';

-- Afficher la structure
DESCRIBE reviews;

-- ============================================================
-- ROLLBACK (En cas de problème)
-- ============================================================

-- Pour annuler la migration:
-- DROP TABLE IF EXISTS reviews;
-- DROP VIEW IF EXISTS seller_review_stats;
-- DROP VIEW IF EXISTS listings_with_ratings;
