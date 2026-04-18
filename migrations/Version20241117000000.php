<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour simplifier l'authentification
 * - Ajouter whatsappPhone et bio
 * - Rendre phone, country et city nullable
 */
final class Version20241117000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Simplification de l\'authentification : ajout whatsappPhone et bio, champs optionnels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET SESSION sql_mode=''");
        // Ajouter les nouveaux champs
        $this->addSql('ALTER TABLE users ADD whatsapp_phone VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD bio TEXT DEFAULT NULL');
        
        // Rendre phone nullable et retirer la contrainte NOT NULL
        $this->addSql('ALTER TABLE users MODIFY COLUMN phone VARCHAR(20) DEFAULT NULL');
        
        // Rendre country et city nullable
        $this->addSql('ALTER TABLE users MODIFY COLUMN country VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE users MODIFY COLUMN city VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Supprimer les champs ajoutés
        $this->addSql('ALTER TABLE users DROP COLUMN whatsapp_phone');
        $this->addSql('ALTER TABLE users DROP COLUMN bio');
        
        // Remettre les contraintes NOT NULL (attention, peut échouer si des valeurs NULL existent)
        $this->addSql('ALTER TABLE users MODIFY COLUMN phone VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE users MODIFY COLUMN country VARCHAR(2) NOT NULL');
        $this->addSql('ALTER TABLE users MODIFY COLUMN city VARCHAR(100) NOT NULL');
    }
}
