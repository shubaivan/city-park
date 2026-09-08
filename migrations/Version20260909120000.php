<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The full-size copy of the cached avatar, opened when somebody taps the small one. */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'telegram_user: photo_full_path (largest cached profile photo)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD photo_full_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user DROP photo_full_path');
    }
}
