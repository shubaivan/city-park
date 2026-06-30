<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.vote_block_count (repeat community-vote-block tally), backfilled from passed campaigns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD COLUMN vote_block_count INT NOT NULL DEFAULT 0');
        // Backfill from any already-passed campaigns so the counter is correct on day one.
        $this->addSql('UPDATE account SET vote_block_count = (
            SELECT COUNT(*) FROM block_vote_campaign c
            WHERE c.candidate_account_id = account.id AND c.status = \'passed\'
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP COLUMN vote_block_count');
    }
}
