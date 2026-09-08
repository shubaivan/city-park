<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A vote of the house can now be a question, not only a proposal to block somebody.
 *
 * One entity for both kinds, as with rent and sale on the noticeboard: everything around a
 * vote is identical — eligibility, one ballot per account, the snapshotted denominator, the
 * deadline, the broadcast, the tally cron, the archive — and what differs is the subject and
 * the consequence. Two tables would be two copies of that machinery, and one of them would
 * rot.
 *
 * `candidate_account_id` becomes nullable because a question puts nobody on trial.
 */
final class Version20260908180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'block_vote_campaign: add kind/question/details, allow a campaign with no candidate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE block_vote_campaign ADD kind VARCHAR(16) DEFAULT 'block' NOT NULL");
        $this->addSql('ALTER TABLE block_vote_campaign ADD question TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE block_vote_campaign ADD details TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE block_vote_campaign ALTER candidate_account_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Questions have no candidate, so they cannot survive the column becoming NOT NULL
        // again. Dropping them is the only honest rollback — and it is why this runs after
        // the delete rather than before it.
        $this->addSql("DELETE FROM block_vote_campaign WHERE kind = 'question'");
        $this->addSql('ALTER TABLE block_vote_campaign ALTER candidate_account_id SET NOT NULL');
        $this->addSql('ALTER TABLE block_vote_campaign DROP kind');
        $this->addSql('ALTER TABLE block_vote_campaign DROP question');
        $this->addSql('ALTER TABLE block_vote_campaign DROP details');
    }
}
