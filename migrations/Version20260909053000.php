<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guest passes, and the log of every QR scan.
 *
 * Written by hand rather than by `migrations:diff`: the developer's local database is
 * months behind prod, so the generated file also wanted to create `link_click` and
 * `service_offer` and drop indexes that are in use. A migration is applied to prod, and
 * prod is not what the diff was taken against.
 */
final class Version20260909053000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guest passes for builders, and the QR scan log behind /admin/scans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE guest_pass_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE guest_pass (
            id INT NOT NULL,
            account_id INT NOT NULL,
            issued_by_id INT DEFAULT NULL,
            label VARCHAR(80) NOT NULL,
            active_on DATE DEFAULT NULL,
            revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_scanned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            scans INT DEFAULT 0 NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX gp_account_idx ON guest_pass (account_id)');
        $this->addSql('CREATE INDEX gp_issued_by_idx ON guest_pass (issued_by_id)');
        $this->addSql('ALTER TABLE guest_pass ADD CONSTRAINT fk_guest_pass_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE guest_pass ADD CONSTRAINT fk_guest_pass_issued_by FOREIGN KEY (issued_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE SEQUENCE qr_scan_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE qr_scan (
            id INT NOT NULL,
            scanned_by_id INT DEFAULT NULL,
            subject_account_id INT DEFAULT NULL,
            kind VARCHAR(16) NOT NULL,
            result VARCHAR(16) NOT NULL,
            scanned_by_label VARCHAR(120) NOT NULL,
            subject_label VARCHAR(120) NOT NULL,
            guest_pass_id INT DEFAULT NULL,
            by_guard BOOLEAN DEFAULT false NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX qs_created_idx ON qr_scan (created_at)');
        $this->addSql('CREATE INDEX qs_scanned_by_idx ON qr_scan (scanned_by_id)');
        $this->addSql('CREATE INDEX qs_subject_idx ON qr_scan (subject_account_id)');
        $this->addSql('ALTER TABLE qr_scan ADD CONSTRAINT fk_qr_scan_scanned_by FOREIGN KEY (scanned_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE qr_scan ADD CONSTRAINT fk_qr_scan_subject FOREIGN KEY (subject_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS qr_scan');
        $this->addSql('DROP SEQUENCE IF EXISTS qr_scan_id_seq');
        $this->addSql('DROP TABLE IF EXISTS guest_pass');
        $this->addSql('DROP SEQUENCE IF EXISTS guest_pass_id_seq');
    }
}
