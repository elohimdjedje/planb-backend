-- ============================================
-- MIGRATION COMPLÈTE - SYSTÈME DE RÉSERVATION
-- ============================================
-- À exécuter dans pgAdmin ou via psql
-- ============================================

-- Table des réservations
CREATE TABLE IF NOT EXISTS bookings (
    id SERIAL PRIMARY KEY,
    listing_id INT NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    tenant_id INT REFERENCES users(id) ON DELETE SET NULL,
    owner_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    check_in_date DATE,
    check_out_date DATE,
    total_amount DECIMAL(12,2) NOT NULL,
    deposit_amount DECIMAL(12,2) NOT NULL,
    monthly_rent DECIMAL(12,2) NOT NULL,
    charges DECIMAL(12,2) DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    deposit_paid BOOLEAN DEFAULT FALSE,
    first_rent_paid BOOLEAN DEFAULT FALSE,
    deposit_released BOOLEAN DEFAULT FALSE,
    requested_at TIMESTAMP DEFAULT NOW(),
    accepted_at TIMESTAMP,
    confirmed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    tenant_message TEXT,
    owner_response TEXT,
    cancellation_reason TEXT,
    CONSTRAINT valid_dates CHECK (end_date > start_date),
    CONSTRAINT valid_amounts CHECK (total_amount > 0 AND deposit_amount > 0)
);

CREATE INDEX IF NOT EXISTS idx_bookings_listing ON bookings(listing_id);
CREATE INDEX IF NOT EXISTS idx_bookings_tenant ON bookings(tenant_id);
CREATE INDEX IF NOT EXISTS idx_bookings_owner ON bookings(owner_id);
CREATE INDEX IF NOT EXISTS idx_bookings_status ON bookings(status);
CREATE INDEX IF NOT EXISTS idx_bookings_dates ON bookings(start_date, end_date);

-- Table des paiements
CREATE TABLE IF NOT EXISTS booking_payments (
    id SERIAL PRIMARY KEY,
    booking_id INT REFERENCES bookings(id) ON DELETE CASCADE,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'XOF',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(20) NOT NULL,
    transaction_id VARCHAR(255),
    external_reference VARCHAR(255),
    due_date DATE,
    paid_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    metadata JSONB,
    CONSTRAINT valid_amount CHECK (amount > 0)
);

CREATE INDEX IF NOT EXISTS idx_booking_payments_booking ON booking_payments(booking_id);
CREATE INDEX IF NOT EXISTS idx_booking_payments_user ON booking_payments(user_id);
CREATE INDEX IF NOT EXISTS idx_booking_payments_status ON booking_payments(status);
CREATE INDEX IF NOT EXISTS idx_booking_payments_due_date ON booking_payments(due_date);

-- Table compte séquestre (Escrow)
CREATE TABLE IF NOT EXISTS escrow_accounts (
    id SERIAL PRIMARY KEY,
    booking_id INT NOT NULL UNIQUE REFERENCES bookings(id) ON DELETE CASCADE,
    deposit_amount DECIMAL(12,2) NOT NULL,
    first_rent_amount DECIMAL(12,2) NOT NULL,
    total_held DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    deposit_held_at TIMESTAMP DEFAULT NOW(),
    deposit_release_date DATE,
    deposit_released_at TIMESTAMP,
    first_rent_released_at TIMESTAMP,
    release_reason TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_escrow_booking ON escrow_accounts(booking_id);
CREATE INDEX IF NOT EXISTS idx_escrow_status ON escrow_accounts(status);

-- Table des contrats
CREATE TABLE IF NOT EXISTS contracts (
    id SERIAL PRIMARY KEY,
    booking_id INT NOT NULL UNIQUE REFERENCES bookings(id) ON DELETE CASCADE,
    template_type VARCHAR(50) NOT NULL,
    contract_data JSONB NOT NULL,
    pdf_url TEXT,
    owner_signed_at TIMESTAMP,
    tenant_signed_at TIMESTAMP,
    owner_signature_url TEXT,
    tenant_signature_url TEXT,
    status VARCHAR(20) DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_contracts_booking ON contracts(booking_id);

-- Table des quittances
CREATE TABLE IF NOT EXISTS receipts (
    id SERIAL PRIMARY KEY,
    payment_id INT NOT NULL REFERENCES booking_payments(id) ON DELETE CASCADE,
    booking_id INT NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    rent_amount DECIMAL(12,2) NOT NULL,
    charges_amount DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL,
    pdf_url TEXT,
    issued_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT valid_period CHECK (period_end > period_start)
);

CREATE INDEX IF NOT EXISTS idx_receipts_payment ON receipts(payment_id);
CREATE INDEX IF NOT EXISTS idx_receipts_booking ON receipts(booking_id);
CREATE INDEX IF NOT EXISTS idx_receipts_number ON receipts(receipt_number);

-- Table calendrier disponibilité
CREATE TABLE IF NOT EXISTS availability_calendar (
    id SERIAL PRIMARY KEY,
    listing_id INT NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    date DATE NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    is_blocked BOOLEAN DEFAULT FALSE,
    price_override DECIMAL(12,2),
    block_reason TEXT,
    UNIQUE(listing_id, date)
);

CREATE INDEX IF NOT EXISTS idx_calendar_listing ON availability_calendar(listing_id);
CREATE INDEX IF NOT EXISTS idx_calendar_date ON availability_calendar(date);
CREATE INDEX IF NOT EXISTS idx_calendar_available ON availability_calendar(listing_id, date, is_available);

-- Table rappels paiement
CREATE TABLE IF NOT EXISTS payment_reminders (
    id SERIAL PRIMARY KEY,
    payment_id INT NOT NULL REFERENCES booking_payments(id) ON DELETE CASCADE,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reminder_type VARCHAR(20) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    email_sent BOOLEAN DEFAULT FALSE,
    sms_sent BOOLEAN DEFAULT FALSE,
    push_sent BOOLEAN DEFAULT FALSE,
    scheduled_at TIMESTAMP NOT NULL,
    sent_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_reminders_payment ON payment_reminders(payment_id);
CREATE INDEX IF NOT EXISTS idx_reminders_user ON payment_reminders(user_id);
CREATE INDEX IF NOT EXISTS idx_reminders_scheduled ON payment_reminders(scheduled_at, status);

-- Table pénalités retard
CREATE TABLE IF NOT EXISTS late_payment_penalties (
    id SERIAL PRIMARY KEY,
    payment_id INT NOT NULL REFERENCES booking_payments(id) ON DELETE CASCADE,
    booking_id INT NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
    days_late INT NOT NULL,
    penalty_rate DECIMAL(5,2) NOT NULL,
    penalty_amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    calculated_at TIMESTAMP DEFAULT NOW(),
    paid_at TIMESTAMP,
    CONSTRAINT valid_days CHECK (days_late > 0),
    CONSTRAINT valid_rate CHECK (penalty_rate >= 0 AND penalty_rate <= 100)
);

CREATE INDEX IF NOT EXISTS idx_penalties_payment ON late_payment_penalties(payment_id);
CREATE INDEX IF NOT EXISTS idx_penalties_booking ON late_payment_penalties(booking_id);

-- Modifications tables existantes
ALTER TABLE listings 
ADD COLUMN IF NOT EXISTS min_rental_days INT DEFAULT 30,
ADD COLUMN IF NOT EXISTS max_rental_days INT,
ADD COLUMN IF NOT EXISTS deposit_months DECIMAL(3,1) DEFAULT 1.0,
ADD COLUMN IF NOT EXISTS advance_notice_days INT DEFAULT 30,
ADD COLUMN IF NOT EXISTS allows_short_term BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS allows_long_term BOOLEAN DEFAULT TRUE;

ALTER TABLE users
ADD COLUMN IF NOT EXISTS bank_account_verified BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS kyc_verified BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS reliability_score INT DEFAULT 100,
ADD COLUMN IF NOT EXISTS late_payments_count INT DEFAULT 0;

-- ============================================
-- VÉRIFICATION
-- ============================================
SELECT 
    table_name,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_name = t.table_name) as column_count
FROM information_schema.tables t
WHERE table_schema = 'public' 
AND table_name IN ('bookings', 'booking_payments', 'escrow_accounts', 'contracts', 'receipts', 'availability_calendar', 'payment_reminders', 'late_payment_penalties')
ORDER BY table_name;
