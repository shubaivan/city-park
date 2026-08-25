<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rental listings: optional apartment photos uploaded from a tokenised web page';
    }

    public function up(Schema $schema): void
    {
        // Photos are stored as an array of public paths rather than a child table: the cap
        // is three per listing, they are only ever read and written as a whole set, and a
        // table would buy ordering we get for free from the array.
        $this->addSql("ALTER TABLE rental_listing ADD photos JSON NOT NULL DEFAULT '[]'");

        // One-shot upload link. Photos deliberately do NOT arrive through Telegram: the
        // photo-obligation cron only materialises a PhotoUploadRequest every 20 minutes,
        // so a pavilion photo sent promptly has no open request yet and any in-bot
        // "is this a flat or the альтанка?" rule would swallow it.
        $this->addSql('ALTER TABLE rental_listing ADD photo_token VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD photo_token_expires_at TIMESTAMP DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX rl_photo_token_idx ON rental_listing (photo_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX rl_photo_token_idx');
        $this->addSql('ALTER TABLE rental_listing DROP COLUMN photos');
        $this->addSql('ALTER TABLE rental_listing DROP COLUMN photo_token');
        $this->addSql('ALTER TABLE rental_listing DROP COLUMN photo_token_expires_at');
    }
}
