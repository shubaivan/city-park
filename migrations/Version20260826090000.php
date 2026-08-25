<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rental listings: opt-in phone contact (show_phone + contact_phone snapshot)';
    }

    public function up(Schema $schema): void
    {
        // Defaults to FALSE so listings published before the opt-in step keep behaving
        // exactly as their owners were told they would: Telegram contact only.
        $this->addSql('ALTER TABLE rental_listing ADD show_phone BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE rental_listing ADD contact_phone VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rental_listing DROP COLUMN show_phone');
        $this->addSql('ALTER TABLE rental_listing DROP COLUMN contact_phone');
    }
}
