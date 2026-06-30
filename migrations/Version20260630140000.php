<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add block_vote_campaign.final_reminder_sent_at for the one-shot last-day reminder to non-voters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_campaign ADD COLUMN final_reminder_sent_at TIMESTAMP DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_campaign DROP COLUMN final_reminder_sent_at');
    }
}
