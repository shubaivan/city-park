<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember which chat message announced a complaint.
 *
 * Two things need it. The register is the author's to withdraw, and a chat that still
 * shows an entry the register no longer has sends neighbours looking for a problem that
 * was retracted. And the group grew topics on 07.09.2026: the open entries were announced
 * before «🔧 Заявки» existed, so a one-off command seeds the branch — which it can only do
 * safely if it can tell what has already been posted.
 */
final class Version20260907150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add complaint.chat_message_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint ADD chat_message_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint DROP chat_message_id');
    }
}
