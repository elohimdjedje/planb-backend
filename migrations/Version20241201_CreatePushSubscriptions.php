<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour créer la table push_subscriptions
 */
final class Version20241201_CreatePushSubscriptions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table push_subscriptions pour les notifications push';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        $this->addSql('
            CREATE TABLE push_subscriptions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh TEXT,
                auth TEXT,
                platform VARCHAR(50) NOT NULL DEFAULT \'web\',
                device_token VARCHAR(255),
                metadata JSON,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME,
                is_active BOOLEAN NOT NULL DEFAULT true,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ');

        $this->addSql('CREATE INDEX idx_push_user ON push_subscriptions(user_id)');
        $this->addSql('CREATE INDEX idx_push_endpoint ON push_subscriptions(endpoint)');
        $this->addSql('CREATE INDEX idx_push_active ON push_subscriptions(is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_push_active');
        $this->addSql('DROP INDEX IF EXISTS idx_push_endpoint');
        $this->addSql('DROP INDEX IF EXISTS idx_push_user');
        $this->addSql('DROP TABLE IF EXISTS push_subscriptions');
    }
}


