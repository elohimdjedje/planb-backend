<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260128_AddContactMessages extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contact_messages table for contact form submissions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        $this->addSql('CREATE TABLE contact_messages (
            id INT AUTO_INCREMENT NOT NULL,
            responded_by_id INT DEFAULT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(180) NOT NULL,
            subject VARCHAR(50) NOT NULL,
            message LONGTEXT NOT NULL,
            status VARCHAR(20) DEFAULT \'pending\' NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            responded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            response LONGTEXT DEFAULT NULL,
            INDEX idx_contact_status (status),
            INDEX idx_contact_created (created_at),
            INDEX IDX_contact_responded_by (responded_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE contact_messages ADD CONSTRAINT FK_contact_responded_by FOREIGN KEY (responded_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_messages DROP FOREIGN KEY FK_contact_responded_by');
        $this->addSql('DROP TABLE contact_messages');
    }
}
