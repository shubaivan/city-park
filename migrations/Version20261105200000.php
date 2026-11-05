<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rental_listing: price currency, UAH or USD; every existing listing stays UAH';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE rental_listing ADD currency VARCHAR(3) DEFAULT 'UAH' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rental_listing DROP currency');
    }
}
