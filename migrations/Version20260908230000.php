<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A ballot records who cast it and when.
 *
 * The account is the unit that votes — one ballot per рахунок — but «хто саме» is the
 * question the panel is asked afterwards, and «кв. 85» does not answer it on a flat with
 * three residents.
 *
 * SET NULL on the person: the ballot is a fact about the vote and must survive them being
 * unlinked or removed. `cast_at` never moves, because a vote is final.
 */
final class Version20260908230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'block_vote_ballot: voter_user_id and cast_at — who voted, and when';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_ballot ADD voter_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE block_vote_ballot ADD cast_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_BVB_VOTER_USER ON block_vote_ballot (voter_user_id)');
        $this->addSql('ALTER TABLE block_vote_ballot ADD CONSTRAINT FK_BVB_VOTER_USER
            FOREIGN KEY (voter_user_id) REFERENCES telegram_user (id) ON DELETE SET NULL
            NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Ballots cast before this migration know their flat but not their person, and no
        // guess would be honest on a household with several residents.
        $this->addSql('UPDATE block_vote_ballot SET cast_at = created_at WHERE cast_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_ballot DROP CONSTRAINT FK_BVB_VOTER_USER');
        $this->addSql('ALTER TABLE block_vote_ballot DROP voter_user_id');
        $this->addSql('ALTER TABLE block_vote_ballot DROP cast_at');
    }
}
