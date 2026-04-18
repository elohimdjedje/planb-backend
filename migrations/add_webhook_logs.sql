-- Migration pour créer la table webhook_logs
-- À exécuter dans PostgreSQL

CREATE TABLE IF NOT EXISTS webhook_logs (
    id SERIAL PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    payload TEXT NOT NULL,
    signature TEXT,
    transaction_id VARCHAR(255),
    event_type VARCHAR(100),
    status VARCHAR(20) NOT NULL DEFAULT 'received',
    error_message TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    processed_at TIMESTAMP(0) WITHOUT TIME ZONE
);

-- Index pour améliorer les performances
CREATE INDEX IF NOT EXISTS idx_webhook_provider_status ON webhook_logs(provider, status);
CREATE INDEX IF NOT EXISTS idx_webhook_transaction ON webhook_logs(transaction_id);
CREATE INDEX IF NOT EXISTS idx_webhook_created ON webhook_logs(created_at);

-- Commentaires
COMMENT ON TABLE webhook_logs IS 'Table pour l''audit des webhooks de paiement Wave et Orange Money';
COMMENT ON COLUMN webhook_logs.provider IS 'Fournisseur: wave ou orange_money';
COMMENT ON COLUMN webhook_logs.status IS 'Statut: received, processing, processed, failed';


