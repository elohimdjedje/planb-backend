-- Migration pour créer la table push_subscriptions
-- À exécuter dans PostgreSQL

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh TEXT,
    auth TEXT,
    platform VARCHAR(50) NOT NULL DEFAULT 'web',
    device_token VARCHAR(255),
    metadata JSON,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    last_used_at TIMESTAMP(0) WITHOUT TIME ZONE,
    is_active BOOLEAN NOT NULL DEFAULT true,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Index pour améliorer les performances
CREATE INDEX IF NOT EXISTS idx_push_user ON push_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_push_endpoint ON push_subscriptions(endpoint);
CREATE INDEX IF NOT EXISTS idx_push_active ON push_subscriptions(is_active);

-- Commentaires
COMMENT ON TABLE push_subscriptions IS 'Table pour les souscriptions push (Web Push API et FCM)';
COMMENT ON COLUMN push_subscriptions.platform IS 'Plateforme: web, ios, android';
COMMENT ON COLUMN push_subscriptions.endpoint IS 'URL du service push (Web Push API)';
COMMENT ON COLUMN push_subscriptions.device_token IS 'Token FCM/APNS pour mobile';


