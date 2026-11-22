<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'qr_scan: whose personal resident code it was (subject_user_id + label); indexes for the ?person= and ?pass= feeds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE qr_scan ADD subject_user_id INT DEFAULT NULL');
        $this->addSql("ALTER TABLE qr_scan ADD subject_user_label VARCHAR(160) DEFAULT '' NOT NULL");
        $this->addSql('CREATE INDEX qs_subject_user_idx ON qr_scan (subject_user_id)');
        $this->addSql('CREATE INDEX qs_guest_pass_idx ON qr_scan (guest_pass_id)');
        $this->addSql('ALTER TABLE qr_scan ADD CONSTRAINT fk_qr_scan_subject_user FOREIGN KEY (subject_user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE qr_scan DROP CONSTRAINT fk_qr_scan_subject_user');
        $this->addSql('DROP INDEX qs_guest_pass_idx');
        $this->addSql('DROP INDEX qs_subject_user_idx');
        $this->addSql('ALTER TABLE qr_scan DROP subject_user_id');
        $this->addSql('ALTER TABLE qr_scan DROP subject_user_label');
    }
}
