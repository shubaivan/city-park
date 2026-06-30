<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community vote-to-block: campaign + ballot tables, plus account.blocked_until for the 30-day time-boxed block';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD COLUMN blocked_until TIMESTAMP DEFAULT NULL');

        $this->addSql('CREATE TABLE block_vote_campaign (
            id SERIAL PRIMARY KEY,
            candidate_account_id INT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
            status VARCHAR(16) NOT NULL DEFAULT \'open\',
            eligible_count INT NOT NULL,
            deadline_at TIMESTAMP NOT NULL,
            closed_at TIMESTAMP DEFAULT NULL,
            result_yes INT DEFAULT NULL,
            result_no INT DEFAULT NULL,
            created_by VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX bvc_status_deadline_idx ON block_vote_campaign (status, deadline_at)');

        $this->addSql('CREATE TABLE block_vote_ballot (
            id SERIAL PRIMARY KEY,
            campaign_id INT NOT NULL REFERENCES block_vote_campaign(id) ON DELETE CASCADE,
            voter_account_id INT NOT NULL REFERENCES account(id) ON DELETE CASCADE,
            value BOOLEAN NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX bvb_campaign_voter_uniq ON block_vote_ballot (campaign_id, voter_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE block_vote_ballot');
        $this->addSql('DROP TABLE block_vote_campaign');
        $this->addSql('ALTER TABLE account DROP COLUMN blocked_until');
    }
}
