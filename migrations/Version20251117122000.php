<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251117122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE listings ADD commune VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE listings ADD quartier VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE listings DROP commune');
        $this->addSql('ALTER TABLE listings DROP quartier');
    }
}
