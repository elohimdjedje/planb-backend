<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * This migration was auto-generated for PostgreSQL and is a no-op on MySQL/MariaDB.
 */
final class Version20251109220328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op: PostgreSQL schema sync (not needed on MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        // No-op on MySQL/MariaDB: was a PostgreSQL-specific schema normalization pass
        $this->addSql("SET SESSION sql_mode=''");
    }

    public function down(Schema $schema): void
    {
        // No-op on MySQL/MariaDB
    }
}