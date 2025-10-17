<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Admin table and update Order entity with orderer information
 */
final class Version20250101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Admin table and update Order entity with orderer information';
    }

    public function up(Schema $schema): void
    {
        // Create admins table
        $this->addSql('CREATE TABLE admins (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, telegram_user_id BIGINT NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, nickname VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, active BOOLEAN NOT NULL)');
        
        // Add orderer information columns to orders table
        $this->addSql('ALTER TABLE orders ADD COLUMN orderer_name VARCHAR(255) NOT NULL DEFAULT \'Unknown\'');
        $this->addSql('ALTER TABLE orders ADD COLUMN orderer_nickname VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD COLUMN orderer_phone VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove orderer information columns from orders table
        $this->addSql('ALTER TABLE orders DROP COLUMN orderer_name');
        $this->addSql('ALTER TABLE orders DROP COLUMN orderer_nickname');
        $this->addSql('ALTER TABLE orders DROP COLUMN orderer_phone');
        
        // Drop admins table
        $this->addSql('DROP TABLE admins');
    }
}