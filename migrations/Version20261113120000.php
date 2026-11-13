<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gate_event: the guard\'s «В\'їзд / Виїзд» under a valid QR scan, one per scan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gate_event (
            id SERIAL NOT NULL,
            qr_scan_id INT DEFAULT NULL,
            guard_id INT DEFAULT NULL,
            subject_account_id INT DEFAULT NULL,
            direction VARCHAR(8) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            guard_label VARCHAR(120) NOT NULL,
            place_label VARCHAR(120) NOT NULL,
            person_label VARCHAR(255) NOT NULL,
            car_label VARCHAR(120) DEFAULT NULL,
            guest_pass_id INT DEFAULT NULL,
            gate VARCHAR(60) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_gate_event_scan ON gate_event (qr_scan_id)');
        $this->addSql('CREATE INDEX ge_created_idx ON gate_event (created_at)');
        $this->addSql('CREATE INDEX ge_guard_idx ON gate_event (guard_id)');
        $this->addSql('CREATE INDEX ge_subject_idx ON gate_event (subject_account_id)');
        $this->addSql('ALTER TABLE gate_event ADD CONSTRAINT fk_gate_event_scan FOREIGN KEY (qr_scan_id) REFERENCES qr_scan (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE gate_event ADD CONSTRAINT fk_gate_event_guard FOREIGN KEY (guard_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE gate_event ADD CONSTRAINT fk_gate_event_subject FOREIGN KEY (subject_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gate_event');
    }
}
