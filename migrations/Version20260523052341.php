<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523052341 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pavilion photo upload tracking (PavilionPhoto + PhotoUploadRequest)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE pavilion_photo_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE photo_upload_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE pavilion_photo (id INT NOT NULL, account_id INT NOT NULL, uploader_telegram_user_id INT DEFAULT NULL, pavilion INT NOT NULL, session_start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, session_end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, file_path VARCHAR(512) NOT NULL, telegram_file_id VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CE4BDA799B6B5FBA ON pavilion_photo (account_id)');
        $this->addSql('CREATE INDEX IDX_CE4BDA7942AB2D4E ON pavilion_photo (uploader_telegram_user_id)');
        $this->addSql('CREATE UNIQUE INDEX pp_session_idx ON pavilion_photo (account_id, pavilion, session_start_at)');
        $this->addSql('CREATE INDEX pp_created_idx ON pavilion_photo (created_at)');
        $this->addSql('CREATE TABLE photo_upload_request (id INT NOT NULL, account_id INT NOT NULL, pavilion INT NOT NULL, session_start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, session_end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reminders_sent INT DEFAULT 0 NOT NULL, last_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, blocked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E693E32C9B6B5FBA ON photo_upload_request (account_id)');
        $this->addSql('CREATE UNIQUE INDEX pur_session_idx ON photo_upload_request (account_id, pavilion, session_start_at)');
        $this->addSql('CREATE INDEX pur_open_idx ON photo_upload_request (resolved_at)');
        $this->addSql('ALTER TABLE pavilion_photo ADD CONSTRAINT FK_CE4BDA799B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE pavilion_photo ADD CONSTRAINT FK_CE4BDA7942AB2D4E FOREIGN KEY (uploader_telegram_user_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE photo_upload_request ADD CONSTRAINT FK_E693E32C9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE pavilion_photo_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE photo_upload_request_id_seq CASCADE');
        $this->addSql('ALTER TABLE pavilion_photo DROP CONSTRAINT FK_CE4BDA799B6B5FBA');
        $this->addSql('ALTER TABLE pavilion_photo DROP CONSTRAINT FK_CE4BDA7942AB2D4E');
        $this->addSql('ALTER TABLE photo_upload_request DROP CONSTRAINT FK_E693E32C9B6B5FBA');
        $this->addSql('DROP TABLE pavilion_photo');
        $this->addSql('DROP TABLE photo_upload_request');
    }
}
