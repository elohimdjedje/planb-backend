-- ============================================================
-- Migration : Système de contractualisation PlanB
-- Base : MySQL 8.0
-- ============================================================
-- Appliquer : SOURCE migrations/add_contract_workflow_columns.sql
-- OU via PHP : php bin/console doctrine:migrations:execute --up

-- ──────────────────────────────────────────────────────────────
-- 1. TABLE contracts — ajout de toutes les nouvelles colonnes
-- ──────────────────────────────────────────────────────────────

ALTER TABLE contracts
    -- Identifiant lisible unique (PLANB-2026-XXXXXX)
    ADD COLUMN IF NOT EXISTS unique_contract_id     VARCHAR(30)    DEFAULT NULL UNIQUE AFTER id,

    -- Hash SHA-256 du document brut (à la génération)
    ADD COLUMN IF NOT EXISTS document_hash          CHAR(64)       DEFAULT NULL AFTER template_type,

    -- Hash SHA-256 du document après double signature (immuable)
    ADD COLUMN IF NOT EXISTS signed_document_hash   CHAR(64)       DEFAULT NULL AFTER document_hash,

    -- Chemin du PDF téléversé par le propriétaire (si template_type = 'uploaded')
    ADD COLUMN IF NOT EXISTS uploaded_pdf_path      TEXT           DEFAULT NULL AFTER signed_document_hash,

    -- Date/heure de verrouillage du contrat
    ADD COLUMN IF NOT EXISTS locked_at              DATETIME       DEFAULT NULL AFTER updated_at,

    -- Métadonnées de signature locataire : {uid, email, ip, user_agent, timestamp, doc_hash}
    ADD COLUMN IF NOT EXISTS tenant_signature_meta  JSON           DEFAULT NULL AFTER tenant_signature_url,

    -- Métadonnées de signature propriétaire
    ADD COLUMN IF NOT EXISTS owner_signature_meta   JSON           DEFAULT NULL AFTER owner_signature_url,

    -- ── Paiement ──
    ADD COLUMN IF NOT EXISTS rent_amount            DECIMAL(12,2)  DEFAULT NULL AFTER locked_at,
    ADD COLUMN IF NOT EXISTS deposit_monthly_amount DECIMAL(12,2)  DEFAULT NULL AFTER rent_amount,
    ADD COLUMN IF NOT EXISTS deposit_months         TINYINT        DEFAULT 2    AFTER deposit_monthly_amount,
    ADD COLUMN IF NOT EXISTS total_payment_amount   DECIMAL(12,2)  DEFAULT NULL AFTER deposit_months,

    -- Statut paiement : payment_pending | payment_success | payment_failed
    ADD COLUMN IF NOT EXISTS payment_status         VARCHAR(30)    DEFAULT NULL AFTER total_payment_amount,

    ADD COLUMN IF NOT EXISTS kkiapay_transaction_id VARCHAR(100)   DEFAULT NULL AFTER payment_status,
    ADD COLUMN IF NOT EXISTS paid_at                DATETIME       DEFAULT NULL AFTER kkiapay_transaction_id,
    ADD COLUMN IF NOT EXISTS receipt_url            TEXT           DEFAULT NULL AFTER paid_at,
    ADD COLUMN IF NOT EXISTS quittance_url          TEXT           DEFAULT NULL AFTER receipt_url,

    -- ── Restitution ──
    -- Statut : restitution_requested | restitution_processing | restitution_validated | restitution_completed
    ADD COLUMN IF NOT EXISTS restitution_status          VARCHAR(40)   DEFAULT NULL AFTER quittance_url,
    ADD COLUMN IF NOT EXISTS restitution_notes           TEXT          DEFAULT NULL AFTER restitution_status,
    ADD COLUMN IF NOT EXISTS restitution_retained_amount DECIMAL(12,2) DEFAULT NULL AFTER restitution_notes,
    ADD COLUMN IF NOT EXISTS restitution_requested_at    DATETIME      DEFAULT NULL AFTER restitution_retained_amount,
    ADD COLUMN IF NOT EXISTS restitution_completed_at    DATETIME      DEFAULT NULL AFTER restitution_requested_at,
    ADD COLUMN IF NOT EXISTS exit_report_url             TEXT          DEFAULT NULL AFTER restitution_completed_at;

-- Mettre à jour les statuts de contrat pour refléter la nouvelle machine à états
-- Anciens statuts : draft | sent | signed | archived
-- Nouveaux statuts : draft | tenant_signed | owner_signed | locked | archived
-- Migration douce des anciens statuts
UPDATE contracts
SET status = 'locked'
WHERE status = 'signed';

UPDATE contracts
SET status = 'draft'
WHERE status = 'sent';

-- Peupler unique_contract_id pour les contrats existants
UPDATE contracts
SET unique_contract_id = CONCAT('PLANB-', YEAR(created_at), '-', LPAD(id, 5, '0'))
WHERE unique_contract_id IS NULL;

-- ──────────────────────────────────────────────────────────────
-- 2. TABLE bookings — ajout du statut 'visited'
-- ──────────────────────────────────────────────────────────────

-- MySQL ENUM : modifier pour ajouter 'visited'
ALTER TABLE bookings
    MODIFY COLUMN status ENUM(
        'pending',
        'accepted',
        'rejected',
        'visited',
        'confirmed',
        'active',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending';

-- ──────────────────────────────────────────────────────────────
-- 3. TABLE contract_audit_logs — journal immuable d'événements
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS contract_audit_logs (
    id                BIGINT      UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id       INT         NOT NULL,
    user_id           INT         DEFAULT NULL,

    -- Type d'événement (cf. ContractAuditLog::EVENT_* constants)
    event_type        VARCHAR(60) NOT NULL,

    -- Description lisible de l'événement
    description       TEXT        NOT NULL,

    -- Contexte JSON libre (montants, décisions, hashes…)
    context           JSON        DEFAULT NULL,

    -- Hash du document au moment de l'événement
    document_hash     CHAR(64)    DEFAULT NULL,

    -- IP du client (IPv4 ou IPv6, max 45 chars)
    ip_address        VARCHAR(45) DEFAULT NULL,

    -- User-Agent navigateur (tronqué à 500 chars)
    user_agent        VARCHAR(500) DEFAULT NULL,

    -- Hash d'intégrité de l'entrée elle-même (SHA-256)
    log_integrity_hash CHAR(64)   DEFAULT NULL,

    -- Immuable : pas d'updated_at
    created_at        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Clés étrangères
    CONSTRAINT fk_cal_contract FOREIGN KEY (contract_id)
        REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cal_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE SET NULL,

    -- Index performances
    INDEX idx_cal_contract     (contract_id),
    INDEX idx_cal_user         (user_id),
    INDEX idx_cal_event_type   (event_type),
    INDEX idx_cal_created_at   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- 4. Vérification manuelle post-migration
-- ──────────────────────────────────────────────────────────────

-- SHOW COLUMNS FROM contracts;
-- SHOW COLUMNS FROM contract_audit_logs;
-- SELECT status, COUNT(*) FROM bookings GROUP BY status;

SELECT 'Migration add_contract_workflow_columns.sql appliquée avec succès ✓' AS result;
