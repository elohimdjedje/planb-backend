-- =====================================================
-- SYSTÈME DE VÉRIFICATION PAR SCOPE
-- Migration: add_scope_verification_system.sql
-- =====================================================

-- 1. Table des documents utilisateur (réutilisables entre scopes)
CREATE TABLE IF NOT EXISTS user_documents (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    doc_type VARCHAR(50) NOT NULL, -- CNI, PASSPORT, PERMIS, KBIS, RC_PRO, DIPLOME, etc.
    file_url TEXT NOT NULL,
    file_name VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'UPLOADED', -- UPLOADED, VALIDATED, REJECTED, EXPIRED
    rejection_reason TEXT,
    validated_at TIMESTAMP,
    expires_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_user_documents_user ON user_documents(user_id);
CREATE INDEX idx_user_documents_status ON user_documents(status);
CREATE UNIQUE INDEX idx_user_documents_unique ON user_documents(user_id, doc_type) WHERE status IN ('UPLOADED', 'VALIDATED');

-- 2. Table de configuration des scopes
CREATE TABLE IF NOT EXISTS scope_config (
    scope_key VARCHAR(50) PRIMARY KEY, -- AUTO, IMMOBILIER, AUTO/CAR_IMPORT, IMMOBILIER/AGENCE
    parent_scope VARCHAR(50) REFERENCES scope_config(scope_key),
    display_name VARCHAR(100) NOT NULL,
    display_name_fr VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50), -- emoji ou nom d'icône
    required_docs TEXT[] NOT NULL DEFAULT '{}', -- ['CNI', 'PERMIS']
    is_strict BOOLEAN NOT NULL DEFAULT false, -- si true, le parent ne couvre pas ce scope
    expiration_days INTEGER DEFAULT 730, -- 2 ans par défaut
    max_rejections INTEGER DEFAULT 3,
    cooldown_hours INTEGER DEFAULT 24,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. Table des vérifications par scope (une ligne par user + scope)
CREATE TABLE IF NOT EXISTS scope_verifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scope_key VARCHAR(50) NOT NULL REFERENCES scope_config(scope_key),
    status VARCHAR(20) NOT NULL DEFAULT 'NOT_STARTED', -- NOT_STARTED, PENDING, APPROVED, REJECTED, EXPIRED, BLOCKED
    submitted_at TIMESTAMP,
    reviewed_at TIMESTAMP,
    reviewed_by INTEGER REFERENCES users(id),
    approved_at TIMESTAMP,
    expires_at TIMESTAMP,
    rejection_count INTEGER NOT NULL DEFAULT 0,
    rejection_reason TEXT,
    blocked_until TIMESTAMP,
    notes TEXT, -- notes internes admin
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, scope_key)
);

CREATE INDEX idx_scope_verifications_user ON scope_verifications(user_id);
CREATE INDEX idx_scope_verifications_status ON scope_verifications(status);
CREATE INDEX idx_scope_verifications_scope ON scope_verifications(scope_key);

-- 4. Table de liaison documents ↔ vérification scope
CREATE TABLE IF NOT EXISTS scope_verification_documents (
    id SERIAL PRIMARY KEY,
    scope_verification_id INTEGER NOT NULL REFERENCES scope_verifications(id) ON DELETE CASCADE,
    user_document_id INTEGER NOT NULL REFERENCES user_documents(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(scope_verification_id, user_document_id)
);

-- 5. Table de mapping catégorie → scope requis
CREATE TABLE IF NOT EXISTS category_scope_map (
    id SERIAL PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    subcategory VARCHAR(50), -- NULL = s'applique à toute la catégorie
    required_scope_key VARCHAR(50) NOT NULL REFERENCES scope_config(scope_key),
    is_mandatory BOOLEAN NOT NULL DEFAULT true, -- si false, vérification optionnelle (badge bonus)
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(category, subcategory)
);

CREATE INDEX idx_category_scope_map_category ON category_scope_map(category);

-- =====================================================
-- DONNÉES INITIALES : Configuration des scopes
-- =====================================================

INSERT INTO scope_config (scope_key, parent_scope, display_name, display_name_fr, icon, required_docs, is_strict, expiration_days) VALUES
-- Domaines principaux
('AUTO', NULL, 'Automotive', 'Automobile', '🚗', ARRAY['CNI'], false, 730),
('IMMOBILIER', NULL, 'Real Estate', 'Immobilier', '🏠', ARRAY['CNI'], false, 730),
('EMPLOI', NULL, 'Jobs', 'Emploi', '💼', ARRAY['CNI'], false, 730),
('SERVICES', NULL, 'Services', 'Services', '🔧', ARRAY['CNI'], false, 730),
('ELECTRONIQUE', NULL, 'Electronics', 'Électronique', '📱', ARRAY[], false, 730),
('MODE', NULL, 'Fashion', 'Mode & Vêtements', '👔', ARRAY[], false, 730),
('MAISON', NULL, 'Home', 'Maison & Jardin', '🏡', ARRAY[], false, 730),
('LOISIRS', NULL, 'Leisure', 'Loisirs', '🎮', ARRAY[], false, 730),

-- Sous-scopes stricts (nécessitent une vérification supplémentaire)
('AUTO/CAR_IMPORT', 'AUTO', 'Imported Vehicles', 'Véhicules importés', '🚘', ARRAY['CNI', 'PERMIS', 'CARTE_GRISE'], true, 365),
('AUTO/PRO', 'AUTO', 'Pro Dealer', 'Concessionnaire Pro', '🏪', ARRAY['CNI', 'KBIS', 'RC_PRO'], true, 365),
('IMMOBILIER/AGENCE', 'IMMOBILIER', 'Real Estate Agency', 'Agence Immobilière', '🏢', ARRAY['CNI', 'KBIS', 'CARTE_PRO_IMMO'], true, 365),
('IMMOBILIER/PROMOTEUR', 'IMMOBILIER', 'Property Developer', 'Promoteur', '🏗️', ARRAY['CNI', 'KBIS', 'RC_PRO'], true, 365),
('EMPLOI/RECRUTEUR', 'EMPLOI', 'Recruiter', 'Recruteur Pro', '👔', ARRAY['CNI', 'KBIS'], true, 365),
('SERVICES/PRO', 'SERVICES', 'Professional Services', 'Prestataire Pro', '⚙️', ARRAY['CNI', 'KBIS', 'RC_PRO'], true, 365)

ON CONFLICT (scope_key) DO NOTHING;

-- =====================================================
-- MAPPING CATÉGORIES → SCOPES
-- =====================================================

INSERT INTO category_scope_map (category, subcategory, required_scope_key, is_mandatory) VALUES
-- Automobile
('auto', NULL, 'AUTO', true),
('auto', 'voitures-importees', 'AUTO/CAR_IMPORT', true),
('auto', 'concessionnaire', 'AUTO/PRO', true),

-- Immobilier
('immobilier', NULL, 'IMMOBILIER', true),
('immobilier', 'agence', 'IMMOBILIER/AGENCE', true),
('immobilier', 'promotion', 'IMMOBILIER/PROMOTEUR', true),

-- Emploi
('emploi', NULL, 'EMPLOI', true),
('emploi', 'recruteur', 'EMPLOI/RECRUTEUR', true),

-- Services
('services', NULL, 'SERVICES', false), -- optionnel pour services basiques
('services', 'professionnel', 'SERVICES/PRO', true),

-- Catégories sans vérification obligatoire (pas d'entrée = pas de scope requis)
-- electronique, mode, maison, loisirs → pas de vérification requise

ON CONFLICT (category, subcategory) DO NOTHING;

-- =====================================================
-- MIGRATION DES UTILISATEURS EXISTANTS
-- =====================================================

-- Créer un scope LEGACY pour les utilisateurs déjà vérifiés
INSERT INTO scope_config (scope_key, parent_scope, display_name, display_name_fr, icon, required_docs, is_strict, expiration_days)
VALUES ('LEGACY_VERIFIED', NULL, 'Legacy Verified', 'Vérifié (ancien système)', '✓', ARRAY[], false, 1095)
ON CONFLICT (scope_key) DO NOTHING;

-- Migrer les utilisateurs avec identity_verified = true vers LEGACY_VERIFIED
INSERT INTO scope_verifications (user_id, scope_key, status, approved_at, expires_at)
SELECT 
    id, 
    'LEGACY_VERIFIED', 
    'APPROVED',
    COALESCE(identity_verified_at, NOW()),
    COALESCE(identity_verified_at, NOW()) + INTERVAL '3 years'
FROM users 
WHERE identity_verified = true
ON CONFLICT (user_id, scope_key) DO NOTHING;

-- =====================================================
-- FONCTION UTILITAIRE : Vérifier si un user peut publier
-- =====================================================

CREATE OR REPLACE FUNCTION can_user_publish_in_category(
    p_user_id INTEGER,
    p_category VARCHAR,
    p_subcategory VARCHAR DEFAULT NULL
) RETURNS TABLE (
    can_publish BOOLEAN,
    required_scope VARCHAR,
    current_status VARCHAR,
    missing_docs TEXT[]
) AS $$
DECLARE
    v_required_scope VARCHAR;
    v_status VARCHAR;
    v_is_strict BOOLEAN;
    v_parent_scope VARCHAR;
    v_required_docs TEXT[];
    v_user_docs TEXT[];
BEGIN
    -- Trouver le scope requis pour cette catégorie
    SELECT csm.required_scope_key INTO v_required_scope
    FROM category_scope_map csm
    WHERE csm.category = p_category 
    AND (csm.subcategory = p_subcategory OR (csm.subcategory IS NULL AND p_subcategory IS NULL))
    ORDER BY csm.subcategory NULLS LAST
    LIMIT 1;
    
    -- Si pas de scope requis, on peut publier
    IF v_required_scope IS NULL THEN
        RETURN QUERY SELECT true, NULL::VARCHAR, NULL::VARCHAR, NULL::TEXT[];
        RETURN;
    END IF;
    
    -- Récupérer les infos du scope
    SELECT sc.is_strict, sc.parent_scope, sc.required_docs 
    INTO v_is_strict, v_parent_scope, v_required_docs
    FROM scope_config sc WHERE sc.scope_key = v_required_scope;
    
    -- Vérifier si l'utilisateur est approuvé pour ce scope
    SELECT sv.status INTO v_status
    FROM scope_verifications sv
    WHERE sv.user_id = p_user_id AND sv.scope_key = v_required_scope
    AND (sv.expires_at IS NULL OR sv.expires_at > NOW());
    
    IF v_status = 'APPROVED' THEN
        RETURN QUERY SELECT true, v_required_scope, v_status, NULL::TEXT[];
        RETURN;
    END IF;
    
    -- Si scope non strict, vérifier le parent
    IF NOT v_is_strict AND v_parent_scope IS NOT NULL THEN
        SELECT sv.status INTO v_status
        FROM scope_verifications sv
        WHERE sv.user_id = p_user_id AND sv.scope_key = v_parent_scope
        AND (sv.expires_at IS NULL OR sv.expires_at > NOW());
        
        IF v_status = 'APPROVED' THEN
            RETURN QUERY SELECT true, v_parent_scope, v_status, NULL::TEXT[];
            RETURN;
        END IF;
    END IF;
    
    -- Calculer les documents manquants
    SELECT ARRAY_AGG(doc) INTO v_user_docs
    FROM user_documents ud
    WHERE ud.user_id = p_user_id AND ud.status = 'VALIDATED';
    
    RETURN QUERY SELECT 
        false, 
        v_required_scope, 
        COALESCE(v_status, 'NOT_STARTED'),
        ARRAY(SELECT unnest(v_required_docs) EXCEPT SELECT unnest(COALESCE(v_user_docs, ARRAY[]::TEXT[])));
END;
$$ LANGUAGE plpgsql;
