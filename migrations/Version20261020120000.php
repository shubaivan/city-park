<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** «🚗 Мої авто»: the car numbers a household has written down, one object per plate. */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'car_plate: residents\' car numbers per object';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE car_plate_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE car_plate (
                id INT NOT NULL,
                account_id INT NOT NULL,
                added_by_id INT DEFAULT NULL,
                plate VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX cp_account_idx ON car_plate (account_id)');
        $this->addSql('CREATE INDEX IDX_AE35528C55B127A4 ON car_plate (added_by_id)');
        $this->addSql('CREATE UNIQUE INDEX cp_plate_uniq ON car_plate (plate)');
        $this->addSql('ALTER TABLE car_plate ADD CONSTRAINT FK_cp_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE car_plate ADD CONSTRAINT FK_cp_added_by FOREIGN KEY (added_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE car_plate');
        $this->addSql('DROP SEQUENCE car_plate_id_seq');
    }
}
