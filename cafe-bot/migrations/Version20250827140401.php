<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250827140401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, telegram_user_id BIGINT NOT NULL, flow VARCHAR(32) NOT NULL, step VARCHAR(64) NOT NULL, data CLOB NOT NULL)');
        $this->addSql('ALTER TABLE menu_items ADD COLUMN photo_url VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE admin_sessions');
        $this->addSql('CREATE TEMPORARY TABLE __temp__menu_items AS SELECT id, name, description, price_cents, active FROM menu_items');
        $this->addSql('DROP TABLE menu_items');
        $this->addSql('CREATE TABLE menu_items (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, price_cents INTEGER NOT NULL, active BOOLEAN NOT NULL)');
        $this->addSql('INSERT INTO menu_items (id, name, description, price_cents, active) SELECT id, name, description, price_cents, active FROM __temp__menu_items');
        $this->addSql('DROP TABLE __temp__menu_items');
    }
}
