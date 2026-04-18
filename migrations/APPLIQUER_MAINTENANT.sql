-- ============================================
-- MIGRATION VISITE VIRTUELLE - À EXÉCUTER MAINTENANT
-- ============================================
-- 
-- Instructions :
-- 1. Ouvrir pgAdmin
-- 2. Se connecter à la base 'planb'
-- 3. Query Tool (clic droit sur la base → Query Tool)
-- 4. Copier-coller tout ce fichier
-- 5. Exécuter (F5 ou bouton ▶)
--
-- ============================================

-- Ajouter les colonnes pour la visite virtuelle
ALTER TABLE listings 
ADD COLUMN IF NOT EXISTS virtual_tour_type VARCHAR(20) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS virtual_tour_url TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS virtual_tour_thumbnail TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS virtual_tour_data JSONB DEFAULT NULL;

-- Créer l'index pour améliorer les performances
CREATE INDEX IF NOT EXISTS idx_listing_virtual_tour ON listings(virtual_tour_type) 
WHERE virtual_tour_type IS NOT NULL;

-- ============================================
-- VÉRIFICATION
-- ============================================
-- Si vous voyez 4 lignes ci-dessous, la migration a réussi !
-- ============================================

SELECT 
    column_name, 
    data_type, 
    is_nullable,
    '✅ Colonne créée' as status
FROM information_schema.columns 
WHERE table_name = 'listings' 
AND column_name LIKE 'virtual_tour%'
ORDER BY column_name;

-- ============================================
-- RÉSULTAT ATTENDU :
-- ============================================
-- virtual_tour_data     | jsonb  | YES | ✅ Colonne créée
-- virtual_tour_thumbnail| text   | YES | ✅ Colonne créée
-- virtual_tour_type     | varchar| YES | ✅ Colonne créée
-- virtual_tour_url      | text   | YES | ✅ Colonne créée
-- ============================================
