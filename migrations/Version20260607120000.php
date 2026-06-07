<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add grace_warning_sent_at to photo_upload_request (final self-upload-window warning, sent once)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo_upload_request ADD grace_warning_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo_upload_request DROP grace_warning_sent_at');
    }
}
