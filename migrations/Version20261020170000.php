<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'car_plate.parking_spot — where the car stands, optional';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE car_plate ADD parking_spot VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE car_plate DROP parking_spot');
    }
}
