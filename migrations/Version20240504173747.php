<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240504173747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE scheduled_set_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE scheduled_set (id INT NOT NULL, telegram_user_id INT DEFAULT NULL, year INT NOT NULL, month INT NOT NULL, day INT NOT NULL, hour INT NOT NULL, pavilion INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9DC695EAFC28B263 ON scheduled_set (telegram_user_id)');
        $this->addSql('ALTER TABLE scheduled_set ADD CONSTRAINT FK_9DC695EAFC28B263 FOREIGN KEY (telegram_user_id) REFERENCES telegram_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE scheduled_set_id_seq CASCADE');
        $this->addSql('ALTER TABLE scheduled_set DROP CONSTRAINT FK_9DC695EAFC28B263');
        $this->addSql('DROP TABLE scheduled_set');
    }
}
