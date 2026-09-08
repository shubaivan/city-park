<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Residents can put a question to the house themselves.
 *
 * `author_id` is who proposed it — separate from `created_by` (an admin login), because
 * they answer different questions: who may withdraw it, and who is accountable for it
 * having been sent to everybody.
 *
 * `broadcast_at` splits creating a vote from broadcasting it, which is the whole design.
 * A resident's question is live and visible the moment they write it; ringing 171 phones
 * is the part that needs somebody accountable, so it waits for an admin — or the vote
 * earns it by collecting ballots on its own, so that a question people care about cannot
 * be buried by nobody approving it.
 */
final class Version20260908220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'block_vote_campaign: author_id and broadcast_at — residents may ask, the push is separate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_campaign ADD author_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE block_vote_campaign ADD broadcast_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_BVC_AUTHOR ON block_vote_campaign (author_id)');
        $this->addSql('ALTER TABLE block_vote_campaign ADD CONSTRAINT FK_BVC_AUTHOR
            FOREIGN KEY (author_id) REFERENCES telegram_user (id) ON DELETE SET NULL
            NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Everything that exists today was opened from the panel and has already been sent.
        $this->addSql('UPDATE block_vote_campaign SET broadcast_at = created_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_campaign DROP CONSTRAINT FK_BVC_AUTHOR');
        $this->addSql('ALTER TABLE block_vote_campaign DROP author_id');
        $this->addSql('ALTER TABLE block_vote_campaign DROP broadcast_at');
    }
}
