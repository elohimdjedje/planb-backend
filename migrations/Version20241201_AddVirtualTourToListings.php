<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter les champs de visite virtuelle aux annonces
 */
final class Version20241201_AddVirtualTourToListings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les champs virtual_tour_type, virtual_tour_url, virtual_tour_thumbnail et virtual_tour_data à la table listings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Ajouter les colonnes pour la visite virtuelle
        $this->addSql('ALTER TABLE listings ADD COLUMN virtual_tour_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE listings ADD COLUMN virtual_tour_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE listings ADD COLUMN virtual_tour_thumbnail TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE listings ADD COLUMN virtual_tour_data JSON DEFAULT NULL');
        
        // Créer un index pour améliorer les performances
        $this->addSql('CREATE INDEX idx_listing_virtual_tour ON listings(virtual_tour_type)');
    }

    public function down(Schema $schema): void
    {
        // Supprimer l'index
        $this->addSql('ALTER TABLE listings DROP INDEX IF EXISTS idx_listing_virtual_tour');
        
        // Supprimer les colonnes
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS virtual_tour_type');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS virtual_tour_url');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS virtual_tour_thumbnail');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS virtual_tour_data');
    }
}


