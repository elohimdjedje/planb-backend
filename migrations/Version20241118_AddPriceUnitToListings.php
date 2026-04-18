<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter le champ price_unit à la table listings
 */
final class Version20241118_AddPriceUnitToListings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le champ price_unit pour gérer l\'unité de prix des locations (mois/heure)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Ajouter la colonne price_unit
        $this->addSql('ALTER TABLE listings ADD price_unit VARCHAR(10) DEFAULT \'mois\'');
    }

    public function down(Schema $schema): void
    {
        // Supprimer la colonne price_unit
        $this->addSql('ALTER TABLE listings DROP COLUMN price_unit');
    }
}
