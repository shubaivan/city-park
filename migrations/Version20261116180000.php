<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_staff (cleaning crew, recognised by phone) + cleaning_report_photo (photo reports reposted to the chat)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_staff (
            id SERIAL NOT NULL,
            phone VARCHAR(32) NOT NULL,
            phone_key VARCHAR(16) NOT NULL,
            label VARCHAR(64) NOT NULL,
            role VARCHAR(16) NOT NULL,
            active BOOLEAN DEFAULT true NOT NULL,
            telegram_id VARCHAR(32) DEFAULT NULL,
            created_by VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            linked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX service_staff_phone_key_uniq ON service_staff (phone_key)');
        $this->addSql('CREATE INDEX service_staff_tg_idx ON service_staff (telegram_id)');

        $this->addSql('CREATE TABLE cleaning_report_photo (
            id SERIAL NOT NULL,
            staff_id INT DEFAULT NULL,
            chat_id VARCHAR(32) NOT NULL,
            message_id INT NOT NULL,
            media_group_id VARCHAR(64) DEFAULT NULL,
            file_id VARCHAR(255) NOT NULL,
            caption TEXT DEFAULT NULL,
            label VARCHAR(64) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX crp_message_uniq ON cleaning_report_photo (chat_id, message_id)');
        $this->addSql('CREATE INDEX crp_group_idx ON cleaning_report_photo (media_group_id)');
        $this->addSql('CREATE INDEX IDX_CRP_STAFF ON cleaning_report_photo (staff_id)');
        $this->addSql('ALTER TABLE cleaning_report_photo ADD CONSTRAINT fk_crp_staff FOREIGN KEY (staff_id) REFERENCES service_staff (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cleaning_report_photo');
        $this->addSql('DROP TABLE service_staff');
    }
}
