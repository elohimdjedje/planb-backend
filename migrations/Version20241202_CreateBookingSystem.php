<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour créer le système complet de réservation et paiement
 */
final class Version20241202_CreateBookingSystem extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée le système complet de réservation, paiement sécurisé, escrow, contrats et quittances';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Table des réservations
        $this->addSql('
            CREATE TABLE bookings (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                deposit_paid BOOLEAN DEFAULT FALSE,
                first_rent_paid BOOLEAN DEFAULT FALSE,
                deposit_released BOOLEAN DEFAULT FALSE,
                requested_at DATETIME DEFAULT NOW(),
                accepted_at DATETIME,
                confirmed_at DATETIME,
                created_at DATETIME DEFAULT NOW(),
                updated_at DATETIME DEFAULT NOW(),
                tenant_message TEXT,
                owner_response TEXT,
                cancellation_reason TEXT,
                CONSTRAINT valid_dates CHECK (end_date > start_date),
                CONSTRAINT valid_amounts CHECK (total_amount > 0 AND deposit_amount > 0)
            )
        ');

        $this->addSql('CREATE INDEX idx_bookings_listing ON bookings(listing_id)');
        $this->addSql('CREATE INDEX idx_bookings_tenant ON bookings(tenant_id)');
        $this->addSql('CREATE INDEX idx_bookings_owner ON bookings(owner_id)');
        $this->addSql('CREATE INDEX idx_bookings_status ON bookings(status)');
        $this->addSql('CREATE INDEX idx_bookings_dates ON bookings(start_date, end_date)');

        // Table des paiements (replace the initial payments table from V20241029 with the booking-specific version)
        $this->addSql('DROP TABLE IF EXISTS payments');
        $this->addSql('
            CREATE TABLE payments (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id INT REFERENCES bookings(id) ON DELETE CASCADE,
                user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                currency VARCHAR(3) DEFAULT \'XOF\',
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                payment_method VARCHAR(20) NOT NULL,
                transaction_id VARCHAR(255),
                external_reference VARCHAR(255),
                due_date DATE,
                paid_at DATETIME,
                created_at DATETIME DEFAULT NOW(),
                metadata JSON,
                CONSTRAINT valid_amount CHECK (amount > 0)
            )
        ');

        $this->addSql('CREATE INDEX idx_payments_booking ON payments(booking_id)');
        $this->addSql('CREATE INDEX idx_payments_user ON payments(user_id)');
        $this->addSql('CREATE INDEX idx_payments_status ON payments(status)');
        $this->addSql('CREATE INDEX idx_payments_due_date ON payments(due_date)');

        // Table compte séquestre (Escrow)
        $this->addSql('
            CREATE TABLE escrow_accounts (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL UNIQUE REFERENCES bookings(id) ON DELETE CASCADE,
                deposit_amount DECIMAL(12,2) NOT NULL,
                first_rent_amount DECIMAL(12,2) NOT NULL,
                total_held DECIMAL(12,2) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                deposit_held_at DATETIME DEFAULT NOW(),
                deposit_release_date DATE,
                deposit_released_at DATETIME,
                first_rent_released_at DATETIME,
                release_reason TEXT,
                created_at DATETIME DEFAULT NOW(),
                updated_at DATETIME DEFAULT NOW()
            )
        ');

        $this->addSql('CREATE INDEX idx_escrow_booking ON escrow_accounts(booking_id)');
        $this->addSql('CREATE INDEX idx_escrow_status ON escrow_accounts(status)');

        // Table des contrats
        $this->addSql('
            CREATE TABLE contracts (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL UNIQUE REFERENCES bookings(id) ON DELETE CASCADE,
                template_type VARCHAR(50) NOT NULL,
                contract_data JSON NOT NULL,
                pdf_url TEXT,
                owner_signed_at DATETIME,
                tenant_signed_at DATETIME,
                owner_signature_url TEXT,
                tenant_signature_url TEXT,
                status VARCHAR(20) DEFAULT \'draft\',
                created_at DATETIME DEFAULT NOW(),
                updated_at DATETIME DEFAULT NOW()
            )
        ');

        $this->addSql('CREATE INDEX idx_contracts_booking ON contracts(booking_id)');

        // Table des quittances
        $this->addSql('
            CREATE TABLE receipts (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                payment_id INT NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
                booking_id INT NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
                receipt_number VARCHAR(50) UNIQUE NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                rent_amount DECIMAL(12,2) NOT NULL,
                charges_amount DECIMAL(12,2) DEFAULT 0,
                total_amount DECIMAL(12,2) NOT NULL,
                pdf_url TEXT,
                issued_at DATETIME DEFAULT NOW(),
                CONSTRAINT valid_period CHECK (period_end > period_start)
            )
        ');

        $this->addSql('CREATE INDEX idx_receipts_payment ON receipts(payment_id)');
        $this->addSql('CREATE INDEX idx_receipts_booking ON receipts(booking_id)');
        $this->addSql('CREATE INDEX idx_receipts_number ON receipts(receipt_number)');

        // Table calendrier disponibilité
        $this->addSql('
            CREATE TABLE availability_calendar (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                listing_id INT NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                date DATE NOT NULL,
                is_available BOOLEAN DEFAULT TRUE,
                is_blocked BOOLEAN DEFAULT FALSE,
                price_override DECIMAL(12,2),
                block_reason TEXT,
                UNIQUE(listing_id, date)
            )
        ');

        $this->addSql('CREATE INDEX idx_calendar_listing ON availability_calendar(listing_id)');
        $this->addSql('CREATE INDEX idx_calendar_date ON availability_calendar(date)');
        $this->addSql('CREATE INDEX idx_calendar_available ON availability_calendar(listing_id, date, is_available)');

        // Table rappels paiement
        $this->addSql('
            CREATE TABLE payment_reminders (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                payment_id INT NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
                user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                reminder_type VARCHAR(20) NOT NULL,
                status VARCHAR(20) DEFAULT \'pending\',
                email_sent BOOLEAN DEFAULT FALSE,
                sms_sent BOOLEAN DEFAULT FALSE,
                push_sent BOOLEAN DEFAULT FALSE,
                scheduled_at DATETIME NOT NULL,
                sent_at DATETIME,
                created_at DATETIME DEFAULT NOW()
            )
        ');

        $this->addSql('CREATE INDEX idx_reminders_payment ON payment_reminders(payment_id)');
        $this->addSql('CREATE INDEX idx_reminders_user ON payment_reminders(user_id)');
        $this->addSql('CREATE INDEX idx_reminders_scheduled ON payment_reminders(scheduled_at, status)');

        // Table pénalités retard
        $this->addSql('
            CREATE TABLE late_payment_penalties (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                payment_id INT NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
                booking_id INT NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
                days_late INT NOT NULL,
                penalty_rate DECIMAL(5,2) NOT NULL,
                penalty_amount DECIMAL(12,2) NOT NULL,
                status VARCHAR(20) DEFAULT \'pending\',
                calculated_at DATETIME DEFAULT NOW(),
                paid_at DATETIME,
                CONSTRAINT valid_days CHECK (days_late > 0),
                CONSTRAINT valid_rate CHECK (penalty_rate >= 0 AND penalty_rate <= 100)
            )
        ');

        $this->addSql('CREATE INDEX idx_penalties_payment ON late_payment_penalties(payment_id)');
        $this->addSql('CREATE INDEX idx_penalties_booking ON late_payment_penalties(booking_id)');

        // Modifications tables existantes
        $this->addSql('
            ALTER TABLE listings 
            ADD COLUMN IF NOT EXISTS min_rental_days INT DEFAULT 30,
            ADD COLUMN IF NOT EXISTS max_rental_days INT,
            ADD COLUMN IF NOT EXISTS deposit_months DECIMAL(3,1) DEFAULT 1.0,
            ADD COLUMN IF NOT EXISTS advance_notice_days INT DEFAULT 30,
            ADD COLUMN IF NOT EXISTS allows_short_term BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS allows_long_term BOOLEAN DEFAULT TRUE
        ');

        $this->addSql('
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS bank_account_verified BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS kyc_verified BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS reliability_score INT DEFAULT 100,
            ADD COLUMN IF NOT EXISTS late_payments_count INT DEFAULT 0
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS late_payment_penalties');
        $this->addSql('DROP TABLE IF EXISTS payment_reminders');
        $this->addSql('DROP TABLE IF EXISTS availability_calendar');
        $this->addSql('DROP TABLE IF EXISTS receipts');
        $this->addSql('DROP TABLE IF EXISTS contracts');
        $this->addSql('DROP TABLE IF EXISTS escrow_accounts');
        $this->addSql('DROP TABLE IF EXISTS payments');
        $this->addSql('DROP TABLE IF EXISTS bookings');

        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS min_rental_days');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS max_rental_days');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS deposit_months');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS advance_notice_days');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS allows_short_term');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS allows_long_term');

        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS bank_account_verified');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS kyc_verified');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS reliability_score');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS late_payments_count');
    }
}
