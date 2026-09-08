<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember the vote's own post in the residents' chat, so the result can be written back
 * into it when the vote closes.
 *
 * Editing rather than posting again: a thread carrying «відкрито голосування» and no ending
 * is exactly how the same question comes back to the chat next spring, which is the thing
 * the archive exists to stop.
 */
final class Version20260908200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'block_vote_campaign.chat_message_id — the post to write the result back into';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_campaign ADD chat_message_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE block_vote_campaign DROP chat_message_id');
    }
}
