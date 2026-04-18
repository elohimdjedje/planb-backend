-- Migration : Table sale_contracts (compromis de vente)
-- Exécuter : psql $DATABASE_URL < migrations/create_sale_contracts.sql

CREATE TABLE IF NOT EXISTS sale_contracts (
    id                     SERIAL PRIMARY KEY,
    unique_contract_id     VARCHAR(60)     DEFAULT NULL UNIQUE,
    offer_id               INTEGER         NOT NULL UNIQUE,
    buyer_id               INTEGER         NOT NULL,
    seller_id              INTEGER         NOT NULL,
    listing_id             INTEGER         NOT NULL,
    sale_price             DECIMAL(15, 2)  NOT NULL,
    commission_amount      DECIMAL(15, 2)  DEFAULT NULL,
    status                 VARCHAR(30)     NOT NULL DEFAULT 'draft',
    buyer_signed_at        TIMESTAMP       DEFAULT NULL,
    buyer_signature_url    TEXT            DEFAULT NULL,
    seller_signed_at       TIMESTAMP       DEFAULT NULL,
    seller_signature_url   TEXT            DEFAULT NULL,
    locked_at              TIMESTAMP       DEFAULT NULL,
    payment_status         VARCHAR(30)     DEFAULT NULL,
    kkiapay_transaction_id VARCHAR(255)    DEFAULT NULL,
    paid_at                TIMESTAMP       DEFAULT NULL,
    completed_at           TIMESTAMP       DEFAULT NULL,
    created_at             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sc_offer    FOREIGN KEY (offer_id)   REFERENCES offers(id)    ON DELETE CASCADE,
    CONSTRAINT fk_sc_buyer    FOREIGN KEY (buyer_id)   REFERENCES users(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_sc_seller   FOREIGN KEY (seller_id)  REFERENCES users(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_sc_listing  FOREIGN KEY (listing_id) REFERENCES listings(id)  ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sc_offer    ON sale_contracts(offer_id);
CREATE INDEX IF NOT EXISTS idx_sc_buyer    ON sale_contracts(buyer_id);
CREATE INDEX IF NOT EXISTS idx_sc_seller   ON sale_contracts(seller_id);
CREATE INDEX IF NOT EXISTS idx_sc_status   ON sale_contracts(status);
CREATE INDEX IF NOT EXISTS idx_sc_listing  ON sale_contracts(listing_id);

-- Trigger auto-update updated_at
CREATE OR REPLACE FUNCTION update_sale_contracts_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sale_contracts_updated_at ON sale_contracts;
CREATE TRIGGER trg_sale_contracts_updated_at
    BEFORE UPDATE ON sale_contracts
    FOR EACH ROW EXECUTE FUNCTION update_sale_contracts_updated_at();
