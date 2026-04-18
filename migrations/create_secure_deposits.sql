-- ============================================================
-- Migration : Système de caution sécurisée (escrow)
-- Plan B — Afrique de l'Ouest & Centrale
-- Compatible MySQL 8.0
-- ============================================================

-- 1. Table des cautions sécurisées
CREATE TABLE IF NOT EXISTS secure_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Relations
    listing_id  INT NOT NULL,
    tenant_id   INT NOT NULL,
    landlord_id INT NOT NULL,

    -- Montants
    deposit_amount    DECIMAL(12,2) NOT NULL,
    commission_amount DECIMAL(12,2) NOT NULL,
    escrowed_amount   DECIMAL(12,2) NOT NULL,

    -- Paiement
    payment_provider VARCHAR(30) DEFAULT NULL,
    payment_method   VARCHAR(30) DEFAULT NULL,
    transaction_id   VARCHAR(255) DEFAULT NULL,
    payment_url      VARCHAR(255) DEFAULT NULL,

    -- Statut
    status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',

    -- Bien loué
    property_type        VARCHAR(30)  NOT NULL,
    property_description TEXT,
    property_address     TEXT,

    -- Pièces d'identité
    tenant_id_type      VARCHAR(50) DEFAULT NULL,
    tenant_id_number    VARCHAR(100) DEFAULT NULL,
    landlord_id_type    VARCHAR(50) DEFAULT NULL,
    landlord_id_number  VARCHAR(100) DEFAULT NULL,

    -- Dates de location
    rental_start_date DATE DEFAULT NULL,
    rental_end_date   DATE DEFAULT NULL,

    -- Workflow
    paid_at           DATETIME DEFAULT NULL,
    end_of_rental_at  DATETIME DEFAULT NULL,
    deadline_72h_at   DATETIME DEFAULT NULL,
    deadline_7j_at    DATETIME DEFAULT NULL,

    -- Signatures électroniques
    landlord_signed_at DATETIME DEFAULT NULL,
    tenant_signed_at   DATETIME DEFAULT NULL,

    -- Certificat PDF
    certificate_pdf_url TEXT DEFAULT NULL,

    -- Déblocage des fonds
    refund_amount_tenant     DECIMAL(12,2) DEFAULT NULL,
    release_amount_landlord  DECIMAL(12,2) DEFAULT NULL,
    tenant_refund_method     VARCHAR(30) DEFAULT NULL,
    landlord_payout_method   VARCHAR(30) DEFAULT NULL,
    refund_transaction_id    VARCHAR(255) DEFAULT NULL,
    payout_transaction_id    VARCHAR(255) DEFAULT NULL,
    funds_released_at        DATETIME DEFAULT NULL,

    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    CONSTRAINT fk_sd_listing  FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_sd_tenant   FOREIGN KEY (tenant_id)   REFERENCES `users`(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sd_landlord FOREIGN KEY (landlord_id) REFERENCES `users`(id)  ON DELETE CASCADE,

    -- Index
    INDEX idx_sd_status (status),
    INDEX idx_sd_tenant (tenant_id),
    INDEX idx_sd_landlord (landlord_id),
    INDEX idx_sd_listing (listing_id),
    INDEX idx_sd_deadline_72h (deadline_72h_at),
    INDEX idx_sd_deadline_7j (deadline_7j_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table des litiges
CREATE TABLE IF NOT EXISTS deposit_disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,

    secure_deposit_id INT NOT NULL,
    reported_by_id    INT NOT NULL,

    damage_description TEXT    NOT NULL,
    estimated_cost     DECIMAL(12,2) NOT NULL,
    photos             JSON    DEFAULT NULL,
    quote_document_url TEXT    DEFAULT NULL,

    status             VARCHAR(25) NOT NULL DEFAULT 'pending',
    tenant_comment     TEXT DEFAULT NULL,
    tenant_responded_at DATETIME DEFAULT NULL,
    resolved_at         DATETIME DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Foreign keys
    CONSTRAINT fk_dd_deposit FOREIGN KEY (secure_deposit_id) REFERENCES secure_deposits(id) ON DELETE CASCADE,
    CONSTRAINT fk_dd_reporter FOREIGN KEY (reported_by_id) REFERENCES `users`(id),

    -- Index
    INDEX idx_dd_deposit (secure_deposit_id),
    INDEX idx_dd_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ajouter les champs caution sécurisée sur les annonces
ALTER TABLE listings ADD COLUMN IF NOT EXISTS secure_deposit_enabled TINYINT(1) DEFAULT 0;
ALTER TABLE listings ADD COLUMN IF NOT EXISTS deposit_amount_required DECIMAL(12,2) DEFAULT NULL;
