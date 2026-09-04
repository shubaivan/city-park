<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes for the lookups the bot makes on every update, and one uniqueness rule.
 *
 * These tables are small — the largest is 1 450 rows — so no single query here was ever
 * slow. What makes them worth indexing is how often they run: `pg_stat_user_tables` on
 * 04.09.2026 showed 100 581 sequential scans of `account` (8.8M rows read), 27 916 of
 * `scheduled_set` (29.9M rows read) and 2.1M rows read from `telegram_user`. A full scan of
 * 173 rows is free; a hundred thousand of them are not.
 *
 * **The account_number index is not about speed.** It is the key the debt import matches on
 * (`findOneBy(['account_number' => …])`), and a duplicate there silently sends one
 * household's arrears to another row — the hazard behind `42076` sitting next to `420076`
 * in the ОСББ's own file. All 173 rows are distinct today, so the constraint can be made
 * real, and from now on the database refuses the mistake instead of the panel having to
 * remember to.
 */
final class Version20260904160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the bot lookup paths; make account_number unique';
    }

    public function up(Schema $schema): void
    {
        // The debt import's matching key, and the one duplicate that would misroute money.
        $this->addSql('CREATE UNIQUE INDEX uniq_account_number ON account (account_number)');

        // Booking limits are asked per day and per month; the existing composite index
        // starts with telegram_user_id, so a "what is booked on this date" query cannot use
        // it and scans the table instead.
        $this->addSql('CREATE INDEX idx_scheduled_set_day ON scheduled_set (year, month, day)');
        $this->addSql('CREATE INDEX idx_scheduled_set_at ON scheduled_set (scheduled_at)');

        // The chat gate and /phone look a person up by number on every knock. Not unique:
        // a family shares a phone, and 186 rows have none at all.
        $this->addSql('CREATE INDEX idx_telegram_user_phone ON telegram_user (phone_number)');

        // Postgres does not index foreign keys by itself, and these two carry every ballot
        // and every campaign lookup.
        $this->addSql('CREATE INDEX idx_bvc_candidate ON block_vote_campaign (candidate_account_id)');
        $this->addSql('CREATE INDEX idx_bvb_voter ON block_vote_ballot (voter_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bvb_voter');
        $this->addSql('DROP INDEX idx_bvc_candidate');
        $this->addSql('DROP INDEX idx_telegram_user_phone');
        $this->addSql('DROP INDEX idx_scheduled_set_at');
        $this->addSql('DROP INDEX idx_scheduled_set_day');
        $this->addSql('DROP INDEX uniq_account_number');
    }
}
