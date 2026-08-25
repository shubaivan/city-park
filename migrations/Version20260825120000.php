<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rental listings ("здається квартира"): owner-published notices visible to every resident in the bot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rental_listing (
            id SERIAL PRIMARY KEY,
            account_id INT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
            author_id INT DEFAULT NULL REFERENCES telegram_user(id) ON DELETE SET NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'active\',
            rooms SMALLINT DEFAULT NULL,
            price INT DEFAULT NULL,
            description TEXT DEFAULT NULL,
            expires_at TIMESTAMP NOT NULL,
            renew_prompt_sent_at TIMESTAMP DEFAULT NULL,
            closed_at TIMESTAMP DEFAULT NULL,
            closed_by VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NULL
        )');

        $this->addSql('CREATE INDEX rl_status_expires_idx ON rental_listing (status, expires_at)');
        $this->addSql('CREATE INDEX rl_account_idx ON rental_listing (account_id)');
        $this->addSql('CREATE INDEX rl_author_idx ON rental_listing (author_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rental_listing');
    }
}
