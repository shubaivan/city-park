<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tariff table (single-row) for area-based debt-threshold price per m²';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tariff (
            id SERIAL PRIMARY KEY,
            price_per_meter NUMERIC(10, 2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )');
        $this->addSql("INSERT INTO tariff (price_per_meter, updated_at) VALUES (13.50, NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tariff');
    }
}
