<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250827140715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE admin_sessions');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, telegram_user_id BIGINT NOT NULL, flow VARCHAR(32) NOT NULL COLLATE "BINARY", step VARCHAR(64) NOT NULL COLLATE "BINARY", data CLOB NOT NULL COLLATE "BINARY")');
    }
}
