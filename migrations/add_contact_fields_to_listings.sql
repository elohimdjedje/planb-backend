-- Migration: Ajout des champs de contact aux annonces
-- Date: 2024-11-27
-- Description: Ajoute contact_phone, contact_whatsapp, contact_email à la table listings

-- Ajout des colonnes
ALTER TABLE listings 
ADD COLUMN contact_phone VARCHAR(20) NULL AFTER expires_at,
ADD COLUMN contact_whatsapp VARCHAR(20) NULL AFTER contact_phone,
ADD COLUMN contact_email VARCHAR(255) NULL AFTER contact_whatsapp;

-- Commentaires
ALTER TABLE listings 
MODIFY COLUMN contact_phone VARCHAR(20) NULL COMMENT 'Numéro de téléphone pour appel et SMS',
MODIFY COLUMN contact_whatsapp VARCHAR(20) NULL COMMENT 'Numéro WhatsApp',
MODIFY COLUMN contact_email VARCHAR(255) NULL COMMENT 'Email de contact';

-- Index pour recherche par email (optionnel mais utile)
CREATE INDEX idx_listing_contact_email ON listings(contact_email);

-- ✅ Migration terminée!

-- ROLLBACK (en cas de besoin):
-- ALTER TABLE listings DROP COLUMN contact_phone, DROP COLUMN contact_whatsapp, DROP COLUMN contact_email;
-- DROP INDEX idx_listing_contact_email ON listings;
