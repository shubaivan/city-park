<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'power_queue + power_schedule_post: the «💡 Світло» topic';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE power_queue_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE power_queue (
            id INT NOT NULL,
            house VARCHAR(8) NOT NULL,
            queue VARCHAR(8) DEFAULT NULL,
            checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX power_queue_house_uniq ON power_queue (house)');
        $this->addSql("COMMENT ON COLUMN power_queue.checked_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN power_queue.changed_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE SEQUENCE power_schedule_post_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE power_schedule_post (
            id INT NOT NULL,
            day DATE NOT NULL,
            signature TEXT NOT NULL,
            source_post INT NOT NULL,
            message_id INT DEFAULT NULL,
            posted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX power_schedule_post_day_uniq ON power_schedule_post (day)');
        $this->addSql("COMMENT ON COLUMN power_schedule_post.day IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN power_schedule_post.posted_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN power_schedule_post.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS power_schedule_post');
        $this->addSql('DROP SEQUENCE IF EXISTS power_schedule_post_id_seq');
        $this->addSql('DROP TABLE IF EXISTS power_queue');
        $this->addSql('DROP SEQUENCE IF EXISTS power_queue_id_seq');
    }
}
