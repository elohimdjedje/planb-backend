-- Migration pour le système de notifications
-- À exécuter dans la base de données MySQL/MariaDB

-- Table des notifications
CREATE TABLE IF NOT EXISTS notification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data JSON,
    priority ENUM('critical', 'high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('unread', 'read', 'archived') DEFAULT 'unread',
    expires_at DATETIME,
    created_at DATETIME NOT NULL,
    read_at DATETIME,
    INDEX idx_user_status (user_id, status),
    INDEX idx_created (created_at),
    INDEX idx_priority (priority),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des préférences de notifications
CREATE TABLE IF NOT EXISTS notification_preference (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    favorites_removed TINYINT(1) DEFAULT 1,
    listing_expired TINYINT(1) DEFAULT 1,
    subscription_expiring TINYINT(1) DEFAULT 1,
    review_received TINYINT(1) DEFAULT 1,
    review_negative_only TINYINT(1) DEFAULT 0,
    email_enabled TINYINT(1) DEFAULT 1,
    push_enabled TINYINT(1) DEFAULT 1,
    email_frequency ENUM('immediate', 'daily', 'weekly') DEFAULT 'immediate',
    do_not_disturb_start TIME,
    do_not_disturb_end TIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des statistiques d'avis
CREATE TABLE IF NOT EXISTS review_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    total_reviews INT DEFAULT 0,
    average_rating DECIMAL(3,2) DEFAULT 0.00,
    rating_1_count INT DEFAULT 0,
    rating_2_count INT DEFAULT 0,
    rating_3_count INT DEFAULT 0,
    rating_4_count INT DEFAULT 0,
    rating_5_count INT DEFAULT 0,
    response_rate DECIMAL(5,2) DEFAULT 0.00,
    avg_response_time_hours INT DEFAULT 0,
    last_updated DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Créer automatiquement les préférences par défaut pour tous les utilisateurs existants
INSERT INTO notification_preference (user_id, created_at, updated_at)
SELECT id, NOW(), NOW()
FROM users
WHERE id NOT IN (SELECT user_id FROM notification_preference);

-- Créer automatiquement les statistiques pour tous les utilisateurs existants
INSERT INTO review_stats (user_id, last_updated)
SELECT id, NOW()
FROM users
WHERE id NOT IN (SELECT user_id FROM review_stats);
