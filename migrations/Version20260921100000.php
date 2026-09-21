<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The phone numbers the ОСББ expects on an object, written down before their owner has
 * ever opened the bot — so the link happens by itself when they do.
 */
final class Version20260921100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'expected_resident: pre-registered phones per object';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE expected_resident_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE expected_resident (
                id INT NOT NULL,
                account_id INT NOT NULL,
                claimed_by_id INT DEFAULT NULL,
                phone VARCHAR(32) NOT NULL,
                phone_key VARCHAR(16) NOT NULL,
                full_name VARCHAR(180) DEFAULT NULL,
                created_by VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX er_account_idx ON expected_resident (account_id)');
        $this->addSql('CREATE UNIQUE INDEX er_phone_key_uniq ON expected_resident (phone_key)');
        $this->addSql('ALTER TABLE expected_resident ADD CONSTRAINT FK_er_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE expected_resident ADD CONSTRAINT FK_er_claimed_by FOREIGN KEY (claimed_by_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE expected_resident');
        $this->addSql('DROP SEQUENCE expected_resident_id_seq');
    }
}
