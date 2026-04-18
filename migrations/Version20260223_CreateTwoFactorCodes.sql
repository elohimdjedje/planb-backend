-- Migration: Create two_factor_codes table for SMS-based 2FA
-- Date: 2026-02-23

CREATE TABLE IF NOT EXISTS two_factor_codes (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_2fa_user FOREIGN KEY (user_id) REFERENCES "users" (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_2fa_user ON two_factor_codes (user_id);
CREATE INDEX IF NOT EXISTS idx_2fa_expires ON two_factor_codes (expires_at);

-- Cleanup: delete expired codes older than 1 hour (maintenance query)
-- DELETE FROM two_factor_codes WHERE expires_at < NOW() - INTERVAL '1 hour';
