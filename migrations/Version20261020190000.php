<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'car_plate: own/rented space, the parking object claimed, and whether it was linked';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE car_plate ADD spot_owned BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE car_plate ADD parking_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE car_plate ADD parking_linked BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE car_plate ADD CONSTRAINT FK_AE35528C1BD1A1C4 FOREIGN KEY (parking_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_AE35528C1BD1A1C4 ON car_plate (parking_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE car_plate DROP CONSTRAINT FK_AE35528C1BD1A1C4');
        $this->addSql('DROP INDEX IDX_AE35528C1BD1A1C4');
        $this->addSql('ALTER TABLE car_plate DROP spot_owned');
        $this->addSql('ALTER TABLE car_plate DROP parking_account_id');
        $this->addSql('ALTER TABLE car_plate DROP parking_linked');
    }
}
