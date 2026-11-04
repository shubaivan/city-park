<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sms_run: a debt SMS run through the queue; sms_log.run_id, one row per number per run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sms_run (id SERIAL NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(64) NOT NULL, total INT NOT NULL, status VARCHAR(16) NOT NULL, stop_reason VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX sms_run_status_idx ON sms_run (status)');
        $this->addSql('ALTER TABLE sms_log ADD run_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sms_log ADD CONSTRAINT fk_sms_run FOREIGN KEY (run_id) REFERENCES sms_run (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX sms_run_phone_uniq ON sms_log (run_id, phone_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX sms_run_phone_uniq');
        $this->addSql('ALTER TABLE sms_log DROP CONSTRAINT fk_sms_run');
        $this->addSql('ALTER TABLE sms_log DROP run_id');
        $this->addSql('DROP TABLE sms_run');
    }
}
