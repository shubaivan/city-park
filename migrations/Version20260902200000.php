<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * complaint.photo_prompt_message_id — the message that handed over the upload link.
 *
 * Kept so it can be rewritten into a confirmation as soon as a photo lands: the Telegram
 * Web App gives the server no "closed" event, so anything that waits for the Готово button
 * never fires for the many people who dismiss the page with the ✕.
 */
final class Version20260902200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add complaint.photo_prompt_message_id so the upload prompt can become a confirmation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint ADD photo_prompt_message_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint DROP photo_prompt_message_id');
    }
}
