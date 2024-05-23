<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240523185220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE account_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE account (id INT NOT NULL, account_number VARCHAR(255) NOT NULL, apartment_number VARCHAR(255) NOT NULL, house_number VARCHAR(255) NOT NULL, street VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE telegram_user ADD account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user DROP own_account');
        $this->addSql('ALTER TABLE telegram_user ADD CONSTRAINT FK_F180F0599B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F180F0599B6B5FBA ON telegram_user (account_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE telegram_user DROP CONSTRAINT FK_F180F0599B6B5FBA');
        $this->addSql('DROP SEQUENCE account_id_seq CASCADE');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP INDEX IDX_F180F0599B6B5FBA');
        $this->addSql('ALTER TABLE telegram_user ADD own_account VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE telegram_user DROP account_id');
    }
}
