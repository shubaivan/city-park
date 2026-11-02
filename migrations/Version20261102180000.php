<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rental_listing: one video per listing, kept as Telegram\'s file_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rental_listing ADD video_file_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD video_file_unique_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD video_thumb_file_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD video_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD video_duration INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD video_mime VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rental_listing DROP video_file_id');
        $this->addSql('ALTER TABLE rental_listing DROP video_file_unique_id');
        $this->addSql('ALTER TABLE rental_listing DROP video_thumb_file_id');
        $this->addSql('ALTER TABLE rental_listing DROP video_size');
        $this->addSql('ALTER TABLE rental_listing DROP video_duration');
        $this->addSql('ALTER TABLE rental_listing DROP video_mime');
    }
}
