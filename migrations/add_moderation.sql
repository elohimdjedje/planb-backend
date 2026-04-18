-- Migration pour ajouter les champs de modération dans users
-- À exécuter dans PostgreSQL

-- Ajouter les champs de modération dans la table users
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS is_banned BOOLEAN NOT NULL DEFAULT false,
ADD COLUMN IF NOT EXISTS is_suspended BOOLEAN NOT NULL DEFAULT false,
ADD COLUMN IF NOT EXISTS warnings_count INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS banned_until TIMESTAMP(0) WITHOUT TIME ZONE,
ADD COLUMN IF NOT EXISTS suspended_until TIMESTAMP(0) WITHOUT TIME ZONE;

-- Créer la table moderation_actions
CREATE TABLE IF NOT EXISTS moderation_actions (
    id SERIAL PRIMARY KEY,
    moderator_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT NOT NULL,
    reason TEXT,
    notes TEXT,
    metadata JSON,
    related_report_id INT,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE,
    FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_report_id) REFERENCES reports(id) ON DELETE SET NULL
);

-- Index pour améliorer les performances
CREATE INDEX IF NOT EXISTS idx_moderation_moderator ON moderation_actions(moderator_id);
CREATE INDEX IF NOT EXISTS idx_moderation_target ON moderation_actions(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_moderation_action_type ON moderation_actions(action_type);
CREATE INDEX IF NOT EXISTS idx_moderation_created ON moderation_actions(created_at);
CREATE INDEX IF NOT EXISTS idx_moderation_expires ON moderation_actions(expires_at);

-- Index pour users (modération)
CREATE INDEX IF NOT EXISTS idx_users_banned ON users(is_banned);
CREATE INDEX IF NOT EXISTS idx_users_suspended ON users(is_suspended);

-- Commentaires
COMMENT ON TABLE moderation_actions IS 'Table pour tracer toutes les actions de modération';
COMMENT ON COLUMN moderation_actions.action_type IS 'Type d''action: hide, delete, warn, suspend, ban, unban, approve';
COMMENT ON COLUMN moderation_actions.target_type IS 'Type de cible: listing, user, message, review';
COMMENT ON COLUMN users.is_banned IS 'Utilisateur banni définitivement';
COMMENT ON COLUMN users.is_suspended IS 'Utilisateur suspendu temporairement';
COMMENT ON COLUMN users.warnings_count IS 'Nombre d''avertissements reçus (bannissement auto à 3)';


