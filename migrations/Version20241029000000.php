<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241029000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création initiale des tables pour Plan B';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Users table
        $this->addSql('CREATE TABLE users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(180) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            roles JSON NOT NULL,
            password VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            account_type VARCHAR(20) NOT NULL,
            country VARCHAR(2) NOT NULL,
            city VARCHAR(100) NOT NULL,
            profile_picture TEXT DEFAULT NULL,
            is_email_verified BOOLEAN NOT NULL DEFAULT FALSE,
            is_phone_verified BOOLEAN NOT NULL DEFAULT FALSE,
            subscription_expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
        
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_444F97DD444F97DD ON users (phone)');

        // Listings table
        $this->addSql('CREATE TABLE listings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            price NUMERIC(12, 2) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            category VARCHAR(50) NOT NULL,
            subcategory VARCHAR(50) DEFAULT NULL,
            type VARCHAR(20) NOT NULL,
            country VARCHAR(2) NOT NULL,
            city VARCHAR(100) NOT NULL,
            address TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            specifications JSON DEFAULT NULL,
            views_count INT NOT NULL DEFAULT 0,
            contacts_count INT NOT NULL DEFAULT 0,
            is_featured BOOLEAN NOT NULL DEFAULT FALSE,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            CONSTRAINT FK_520D4EDAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )');
        
        $this->addSql('CREATE INDEX IDX_520D4EDAA76ED395 ON listings (user_id)');
        $this->addSql('CREATE INDEX idx_listing_status ON listings (status)');
        $this->addSql('CREATE INDEX idx_listing_category ON listings (category)');
        $this->addSql('CREATE INDEX idx_listing_location ON listings (country, city)');
        $this->addSql('CREATE INDEX idx_listing_created ON listings (created_at)');

        // Images table
        $this->addSql('CREATE TABLE images (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            listing_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            url VARCHAR(500) NOT NULL,
            thumbnail_url VARCHAR(500) DEFAULT NULL,
            `key` VARCHAR(255) DEFAULT NULL,
            order_position INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            uploaded_at DATETIME NOT NULL,
            CONSTRAINT FK_E01FBE6AD4619D1A FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE,
            CONSTRAINT FK_E01FBE6AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )');
        
        $this->addSql('CREATE INDEX IDX_E01FBE6AD4619D1A ON images (listing_id)');
        $this->addSql('CREATE INDEX IDX_E01FBE6AA76ED395 ON images (user_id)');

        // Payments table
        $this->addSql('CREATE TABLE payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount NUMERIC(10, 2) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            transaction_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            description TEXT NOT NULL,
            error_message TEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            CONSTRAINT FK_65D29B32A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )');
        
        $this->addSql('CREATE INDEX IDX_65D29B32A76ED395 ON payments (user_id)');
        $this->addSql('CREATE INDEX idx_payment_status ON payments (status)');
        $this->addSql('CREATE INDEX idx_payment_transaction ON payments (transaction_id)');

        // Subscriptions table
        $this->addSql('CREATE TABLE subscriptions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            account_type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            start_date DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            auto_renew BOOLEAN NOT NULL DEFAULT FALSE,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT FK_4778A01A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )');
        
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4778A01A76ED395 ON subscriptions (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subscriptions');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE images');
        $this->addSql('DROP TABLE listings');
        $this->addSql('DROP TABLE users');
    }
}
