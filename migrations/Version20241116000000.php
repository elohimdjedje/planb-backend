<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour les tables orders et operations
 * Intégration paiements Wave et Orange Money
 */
final class Version20241116000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des tables orders et operations pour la gestion des paiements entre clients et prestataires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Table orders
        $this->addSql('CREATE TABLE orders (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            provider_id INT NOT NULL,
            amount NUMERIC(12, 2) NOT NULL,
            payment_method VARCHAR(50) DEFAULT NULL,
            wave_session_id VARCHAR(255) DEFAULT NULL,
            om_transaction_id VARCHAR(255) DEFAULT NULL,
            om_payment_token VARCHAR(255) DEFAULT NULL,
            api_status VARCHAR(100) DEFAULT NULL,
            api_code VARCHAR(50) DEFAULT NULL,
            api_transaction_id VARCHAR(255) DEFAULT NULL,
            api_transaction_date DATETIME DEFAULT NULL,
            status BOOLEAN DEFAULT FALSE,
            description TEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_orders_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
        )');

        // Index pour optimiser les requêtes
        $this->addSql('CREATE INDEX idx_order_status ON orders(status)');
        $this->addSql('CREATE INDEX idx_wave_session ON orders(wave_session_id)');
        $this->addSql('CREATE INDEX idx_om_transaction ON orders(om_transaction_id)');
        $this->addSql('CREATE INDEX idx_order_client ON orders(client_id)');
        $this->addSql('CREATE INDEX idx_order_provider ON orders(provider_id)');

        // Table operations
        $this->addSql('CREATE TABLE operations (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            provider_id INT DEFAULT NULL,
            order_id INT DEFAULT NULL,
            payment_method VARCHAR(50) DEFAULT NULL,
            sens VARCHAR(10) NOT NULL,
            amount NUMERIC(12, 2) DEFAULT 0.00,
            balance_before NUMERIC(12, 2) DEFAULT 0.00,
            balance_after NUMERIC(12, 2) DEFAULT 0.00,
            description TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_operations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_operations_provider FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_operations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
            CONSTRAINT check_sens CHECK (sens IN (\'in\', \'out\'))
        )');

        // Index pour optimiser les requêtes
        $this->addSql('CREATE INDEX idx_operation_sens ON operations(sens)');
        $this->addSql('CREATE INDEX idx_operation_date ON operations(created_at)');
        $this->addSql('CREATE INDEX idx_operation_user ON operations(user_id)');
        $this->addSql('CREATE INDEX idx_operation_order ON operations(order_id)');
    }

    public function down(Schema $schema): void
    {
        // Supprimer les tables dans l'ordre inverse (à cause des foreign keys)
        $this->addSql('DROP TABLE IF EXISTS operations');
        $this->addSql('DROP TABLE IF EXISTS orders');
    }
}
