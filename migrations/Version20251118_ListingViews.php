<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour créer la table listing_views
 * Système de comptage optimisé des vues
 */
final class Version20251118_ListingViews extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Créer la table listing_views pour le comptage optimisé des vues';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Créer la table listing_views
        $this->addSql('
            CREATE TABLE listing_views (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                listing_id INTEGER NOT NULL,
                user_id INTEGER NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT NULL,
                referrer VARCHAR(500) NULL,
                viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_listing_views_listing 
                    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
            )
        ');

        // Index pour les performances
        $this->addSql('CREATE INDEX idx_listing_viewed_at ON listing_views(listing_id, viewed_at)');
        $this->addSql('CREATE INDEX idx_user_ip ON listing_views(user_id, ip_address)');
        $this->addSql('CREATE INDEX idx_viewed_at ON listing_views(viewed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS listing_views');
    }
}
