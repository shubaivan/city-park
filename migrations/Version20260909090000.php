<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cached Telegram avatars for the admin panel.
 *
 * `photo_path` is a bare file name under `var/avatars/`, never a URL and never a path —
 * the picture is served through /admin/avatar/{id}, behind the panel's login, because it
 * is a resident's face.
 */
final class Version20260909090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'telegram_user: photo_path + photo_checked_at (cached profile photos)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD photo_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user ADD photo_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP photo_path');
        $this->addSql('ALTER TABLE telegram_user DROP photo_checked_at');
    }
}
