<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter l'unité 'nuit' au champ price_unit
 */
final class Version20251129_AddNuitPriceUnit extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l\'unité \"nuit\" comme option valide pour le champ price_unit des locations de vacances';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // La colonne price_unit existe déjà avec VARCHAR(10)
        // 'nuit' fait 4 caractères, donc aucune modification de structure nécessaire
        // Cette migration sert principalement de documentation
        
        $this->addSql('SELECT 1'); // Migration no-op, juste pour la documentation
    }

    public function down(Schema $schema): void
    {
        // Pas de changement à annuler
        $this->addSql('SELECT 1');
    }
}
