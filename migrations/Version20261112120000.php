<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'panel_login_token: the guard\'s one-time panel links from the bot (SHA-256 only, 5 minutes, single use)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE panel_login_token (
            id SERIAL NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            telegram_id VARCHAR(32) NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_panel_login_token_hash ON panel_login_token (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE panel_login_token');
    }
}
