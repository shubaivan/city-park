<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * debt_snapshot.announced_message_id — the Telegram id of the chat announcement.
 *
 * Kept so next month's post can unpin the previous one; without it the group's pinned
 * list would grow by one every import.
 */
final class Version20260902160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add debt_snapshot.announced_message_id so the previous announcement can be unpinned';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debt_snapshot ADD announced_message_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debt_snapshot DROP announced_message_id');
    }
}
