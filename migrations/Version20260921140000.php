<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The journal of every SMS the bot tried to send: to whom, when, what, and what came back. */
final class Version20260921140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sms_log: one row per SMS attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE sms_log_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE sms_log (
                id INT NOT NULL,
                account_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                phone VARCHAR(32) NOT NULL,
                phone_key VARCHAR(16) NOT NULL,
                place_label VARCHAR(120) DEFAULT NULL,
                text TEXT NOT NULL,
                parts INT NOT NULL,
                status VARCHAR(16) NOT NULL,
                provider_id VARCHAR(64) DEFAULT NULL,
                error VARCHAR(255) DEFAULT NULL,
                sent_by VARCHAR(64) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX sms_created_idx ON sms_log (created_at)');
        $this->addSql('CREATE INDEX sms_phone_key_idx ON sms_log (phone_key)');
        $this->addSql('ALTER TABLE sms_log ADD CONSTRAINT FK_sms_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sms_log ADD CONSTRAINT FK_sms_user FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sms_log');
        $this->addSql('DROP SEQUENCE sms_log_id_seq');
    }
}
