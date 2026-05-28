<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create account_status_log audit table for every is_active transition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_status_log (
            id SERIAL PRIMARY KEY,
            account_id INT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
            old_active BOOLEAN NOT NULL,
            new_active BOOLEAN NOT NULL,
            actor_username VARCHAR(64) DEFAULT NULL,
            source VARCHAR(32) NOT NULL,
            reason_code VARCHAR(32) DEFAULT NULL,
            reason_text TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )');
        $this->addSql('CREATE INDEX asl_account_created_idx ON account_status_log (account_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_status_log');
    }
}
