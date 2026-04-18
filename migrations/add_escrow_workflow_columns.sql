-- ============================================================
-- Migration : Nouveau workflow caution sécurisée (escrow)
-- Ajout des colonnes de signatures et restitution
-- Compatible MySQL 8.0
-- ============================================================

-- 1. Nouvelles colonnes de signature et workflow
ALTER TABLE secure_deposits
    ADD COLUMN IF NOT EXISTS admin_signed_at           DATETIME DEFAULT NULL AFTER tenant_signed_at,
    ADD COLUMN IF NOT EXISTS termination_requested_at  DATETIME DEFAULT NULL AFTER admin_signed_at,
    ADD COLUMN IF NOT EXISTS admin_review_at           DATETIME DEFAULT NULL AFTER termination_requested_at,
    ADD COLUMN IF NOT EXISTS landlord_inspection_at    DATETIME DEFAULT NULL AFTER admin_review_at,
    ADD COLUMN IF NOT EXISTS landlord_inspection_notes TEXT     DEFAULT NULL AFTER landlord_inspection_at,
    ADD COLUMN IF NOT EXISTS landlord_exit_signed_at   DATETIME DEFAULT NULL AFTER landlord_inspection_notes,
    ADD COLUMN IF NOT EXISTS tenant_exit_signed_at     DATETIME DEFAULT NULL AFTER landlord_exit_signed_at,
    ADD COLUMN IF NOT EXISTS admin_final_signed_at     DATETIME DEFAULT NULL AFTER tenant_exit_signed_at;

-- 2. Changer le statut par défaut de 'pending_payment' à 'draft'
ALTER TABLE secure_deposits ALTER COLUMN status SET DEFAULT 'draft';

-- 3. Mettre à jour les dépôts existants en 'pending_payment' vers 'draft' (optionnel)
-- UPDATE secure_deposits SET status = 'draft' WHERE status = 'pending_payment' AND paid_at IS NULL AND tenant_signed_at IS NULL;
