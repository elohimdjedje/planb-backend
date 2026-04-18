<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates messaging, favorites, reports, reviews, refresh_tokens, security_logs tables.
 * Rewritten for MySQL/MariaDB compatibility.
 */
final class Version20251128111713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creer tables conversations, favorites, messages, refresh_tokens, reports, reviews, security_logs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");

        $this->addSql('CREATE TABLE conversations (
            id INT NOT NULL AUTO_INCREMENT,
            listing_id INT NOT NULL,
            buyer_id INT NOT NULL,
            seller_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            last_message_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_C2521BF1D4619D1A (listing_id),
            INDEX idx_conversation_buyer (buyer_id),
            INDEX idx_conversation_seller (seller_id),
            INDEX idx_conversation_last_message (last_message_at),
            UNIQUE INDEX listing_buyer_unique (listing_id, buyer_id),
            CONSTRAINT FK_C2521BF1D4619D1A FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE,
            CONSTRAINT FK_C2521BF16C755722 FOREIGN KEY (buyer_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_C2521BF18DE820D9 FOREIGN KEY (seller_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE favorites (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            listing_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_favorite_user (user_id),
            INDEX idx_favorite_listing (listing_id),
            UNIQUE INDEX user_listing_unique (user_id, listing_id),
            CONSTRAINT FK_E46960F5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_E46960F5D4619D1A FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE messages (
            id INT NOT NULL AUTO_INCREMENT,
            conversation_id INT NOT NULL,
            sender_id INT NOT NULL,
            content TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            read_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX idx_message_conversation (conversation_id),
            INDEX idx_message_sender (sender_id),
            INDEX idx_message_created (created_at),
            INDEX idx_message_read (is_read),
            CONSTRAINT FK_DB021E969AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
            CONSTRAINT FK_DB021E96F624B39D FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE refresh_tokens (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_9BACE7E15F37A13B (token),
            INDEX IDX_9BACE7E1A76ED395 (user_id),
            INDEX idx_refresh_token (token),
            INDEX idx_refresh_expires (expires_at),
            CONSTRAINT FK_9BACE7E1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE reports (
            id INT NOT NULL AUTO_INCREMENT,
            reporter_id INT DEFAULT NULL,
            listing_id INT NOT NULL,
            reason VARCHAR(50) NOT NULL,
            description TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            admin_notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_F11FA745E1CFE6F5 (reporter_id),
            INDEX idx_report_status (status),
            INDEX idx_report_listing (listing_id),
            INDEX idx_report_created (created_at),
            CONSTRAINT FK_F11FA745E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT FK_F11FA745D4619D1A FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE reviews (
            id INT NOT NULL AUTO_INCREMENT,
            listing_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            seller_id INT NOT NULL,
            rating SMALLINT NOT NULL,
            comment TEXT DEFAULT NULL,
            review_type VARCHAR(50) NOT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_review_listing (listing_id),
            INDEX idx_review_reviewer (reviewer_id),
            INDEX idx_review_seller (seller_id),
            INDEX idx_review_created (created_at),
            CONSTRAINT FK_6970EB0FD4619D1A FOREIGN KEY (listing_id) REFERENCES listings (id),
            CONSTRAINT FK_6970EB0F70574616 FOREIGN KEY (reviewer_id) REFERENCES users (id),
            CONSTRAINT FK_6970EB0F8DE820D9 FOREIGN KEY (seller_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE security_logs (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT DEFAULT NULL,
            context JSON DEFAULT NULL,
            severity VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_security_user (user_id),
            INDEX idx_security_action (action),
            INDEX idx_security_created (created_at),
            INDEX idx_security_ip (ip_address),
            CONSTRAINT FK_2F9E4A9DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Add contact fields to listings
        $this->addSql('ALTER TABLE listings ADD contact_phone VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE listings ADD contact_whatsapp VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE listings ADD contact_email VARCHAR(255) DEFAULT NULL');

        // Expand country field from VARCHAR(2) to VARCHAR(100)
        $this->addSql('ALTER TABLE users MODIFY COLUMN country VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS security_logs');
        $this->addSql('DROP TABLE IF EXISTS reviews');
        $this->addSql('DROP TABLE IF EXISTS reports');
        $this->addSql('DROP TABLE IF EXISTS refresh_tokens');
        $this->addSql('DROP TABLE IF EXISTS messages');
        $this->addSql('DROP TABLE IF EXISTS favorites');
        $this->addSql('DROP TABLE IF EXISTS conversations');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS contact_phone');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS contact_whatsapp');
        $this->addSql('ALTER TABLE listings DROP COLUMN IF EXISTS contact_email');
    }
}