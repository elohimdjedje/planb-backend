<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter le champ isLifetimePro aux utilisateurs
 */
final class Version20241103000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du champ is_lifetime_pro pour les comptes PRO illimités';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Ajouter la colonne is_lifetime_pro
        $this->addSql('ALTER TABLE users ADD COLUMN is_lifetime_pro BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        // Supprimer la colonne is_lifetime_pro
        $this->addSql('ALTER TABLE users DROP COLUMN is_lifetime_pro');
    }
}
