<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.area (m²) populated from column D of the debt upload file';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD area NUMERIC(6, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP area');
    }
}
