-- ============================================================
-- Migration : Colonnes workflow contrat PlanB — PostgreSQL
-- Date      : 2026-02-24
-- Appliquer : psql -d <database> -f migrations/Version20260224_ContractWorkflow.sql
-- ============================================================

-- Si la table contracts n'existe pas encore, la créer complètement
CREATE TABLE IF NOT EXISTS contracts (
    id                          SERIAL PRIMARY KEY,
    unique_contract_id          VARCHAR(50)     DEFAULT NULL UNIQUE,
    booking_id                  INTEGER         NOT NULL UNIQUE,
    template_type               VARCHAR(50)     NOT NULL DEFAULT 'furnished_rental',
    contract_data               JSONB           NOT NULL DEFAULT '{}',
    pdf_url                     TEXT            DEFAULT NULL,
    uploaded_pdf_path           TEXT            DEFAULT NULL,
    document_hash               CHAR(64)        DEFAULT NULL,
    signed_document_hash        CHAR(64)        DEFAULT NULL,
    -- Signature locataire
    tenant_signed_at            TIMESTAMP       DEFAULT NULL,
    tenant_signature_url        TEXT            DEFAULT NULL,
    tenant_signature_meta       JSONB           DEFAULT NULL,
    -- Signature propriétaire
    owner_signed_at             TIMESTAMP       DEFAULT NULL,
    owner_signature_url         TEXT            DEFAULT NULL,
    owner_signature_meta        JSONB           DEFAULT NULL,
    -- Verrouillage
    locked_at                   TIMESTAMP       DEFAULT NULL,
    -- Statut machine à états
    status                      VARCHAR(30)     NOT NULL DEFAULT 'draft',
    -- Paiement Kkiapay
    rent_amount                 DECIMAL(12,2)   DEFAULT NULL,
    deposit_monthly_amount      DECIMAL(12,2)   DEFAULT NULL,
    deposit_months              SMALLINT        NOT NULL DEFAULT 1,
    total_payment_amount        DECIMAL(12,2)   DEFAULT NULL,
    payment_status              VARCHAR(30)     DEFAULT NULL,
    kkiapay_transaction_id      VARCHAR(255)    DEFAULT NULL,
    paid_at                     TIMESTAMP       DEFAULT NULL,
    receipt_url                 TEXT            DEFAULT NULL,
    quittance_url               TEXT            DEFAULT NULL,
    -- Restitution caution
    restitution_status          VARCHAR(40)     DEFAULT NULL,
    restitution_notes           TEXT            DEFAULT NULL,
    restitution_retained_amount DECIMAL(12,2)   DEFAULT NULL,
    restitution_requested_at    TIMESTAMP       DEFAULT NULL,
    restitution_completed_at    TIMESTAMP       DEFAULT NULL,
    exit_report_url             TEXT            DEFAULT NULL,
    -- Dates
    created_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contracts_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- Si la table existait déjà, ajouter les colonnes manquantes (idempotent)
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS unique_contract_id          VARCHAR(50)   DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS document_hash               CHAR(64)      DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS signed_document_hash        CHAR(64)      DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS uploaded_pdf_path           TEXT          DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS locked_at                   TIMESTAMP     DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS tenant_signature_meta       JSONB         DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS owner_signature_meta        JSONB         DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS rent_amount                 DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS deposit_monthly_amount      DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS deposit_months              SMALLINT      NOT NULL DEFAULT 1;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS total_payment_amount        DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS payment_status              VARCHAR(30)   DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS kkiapay_transaction_id      VARCHAR(255)  DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS paid_at                     TIMESTAMP     DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS receipt_url                 TEXT          DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS quittance_url               TEXT          DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS restitution_status          VARCHAR(40)   DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS restitution_notes           TEXT          DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS restitution_retained_amount DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS restitution_requested_at    TIMESTAMP     DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS restitution_completed_at    TIMESTAMP     DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN IF NOT EXISTS exit_report_url             TEXT          DEFAULT NULL;

-- Ajouter contrainte UNIQUE sur unique_contract_id si absente
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'uq_contracts_unique_contract_id'
    ) THEN
        ALTER TABLE contracts ADD CONSTRAINT uq_contracts_unique_contract_id UNIQUE (unique_contract_id);
    END IF;
END$$;

-- Index performances
CREATE INDEX IF NOT EXISTS idx_contracts_booking    ON contracts (booking_id);
CREATE INDEX IF NOT EXISTS idx_contracts_unique_id  ON contracts (unique_contract_id);
CREATE INDEX IF NOT EXISTS idx_contracts_status     ON contracts (status);
CREATE INDEX IF NOT EXISTS idx_contracts_payment    ON contracts (payment_status);

-- Migration douce des anciens statuts
UPDATE contracts SET status = 'locked' WHERE status = 'signed';
UPDATE contracts SET status = 'draft'  WHERE status = 'sent';

-- Peupler unique_contract_id pour les contrats existants
UPDATE contracts
SET unique_contract_id = 'PLANB-' || EXTRACT(YEAR FROM created_at)::TEXT || '-' || LPAD(id::TEXT, 5, '0')
WHERE unique_contract_id IS NULL;

-- ============================================================
-- TABLE contract_audit_logs — journal immuable des événements
-- ============================================================
CREATE TABLE IF NOT EXISTS contract_audit_logs (
    id                  SERIAL PRIMARY KEY,
    contract_id         INTEGER         NOT NULL,
    user_id             INTEGER         DEFAULT NULL,
    event_type          VARCHAR(50)     NOT NULL,
    description         TEXT            NOT NULL DEFAULT '',
    context             JSONB           NOT NULL DEFAULT '{}',
    document_hash       CHAR(64)        DEFAULT NULL,
    ip_address          VARCHAR(45)     DEFAULT NULL,
    user_agent          TEXT            DEFAULT NULL,
    log_integrity_hash  CHAR(64)        DEFAULT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_audit_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_contract ON contract_audit_logs (contract_id);
CREATE INDEX IF NOT EXISTS idx_audit_event    ON contract_audit_logs (event_type);
CREATE INDEX IF NOT EXISTS idx_audit_created  ON contract_audit_logs (created_at);
