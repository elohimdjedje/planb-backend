-- Migration pour ajouter le champ is_verified à la table users
-- Date: 2026-02-16
-- Description: Ajout du champ de vérification utilisateur pour le badge bleu

ALTER TABLE users ADD COLUMN is_verified BOOLEAN DEFAULT FALSE NOT NULL;
