<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'booking_log: every booked, refused and removed pavilion hour';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE booking_log_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE booking_log (
            id INT NOT NULL,
            action VARCHAR(16) NOT NULL,
            actor_id INT DEFAULT NULL,
            actor_label VARCHAR(160) NOT NULL,
            account_id INT DEFAULT NULL,
            place_label VARCHAR(120) NOT NULL,
            pavilion INT NOT NULL,
            scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            scheduled_set_id INT DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX bl_created_idx ON booking_log (created_at)');
        $this->addSql('CREATE INDEX bl_scheduled_idx ON booking_log (scheduled_at)');
        $this->addSql('CREATE INDEX IDX_A95A511210DAF24A ON booking_log (actor_id)');
        $this->addSql('CREATE INDEX IDX_A95A51129B6B5FBA ON booking_log (account_id)');
        $this->addSql('ALTER TABLE booking_log ADD CONSTRAINT fk_booking_log_actor FOREIGN KEY (actor_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE booking_log ADD CONSTRAINT fk_booking_log_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS booking_log');
        $this->addSql('DROP SEQUENCE IF EXISTS booking_log_id_seq');
    }
}
