<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'rental_listing: the object is typed by the author — kind, building, number, entrance, floor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rental_listing ADD object_type VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD object_house VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD object_number VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD entrance SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE rental_listing ADD floor SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rental_listing DROP object_type');
        $this->addSql('ALTER TABLE rental_listing DROP object_house');
        $this->addSql('ALTER TABLE rental_listing DROP object_number');
        $this->addSql('ALTER TABLE rental_listing DROP entrance');
        $this->addSql('ALTER TABLE rental_listing DROP floor');
    }
}
