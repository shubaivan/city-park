<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'power_schedule_post: reminders ten minutes before an outage and at its end';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE power_schedule_post ADD reminders_sent JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE power_schedule_post ADD reminder_message_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE power_schedule_post DROP reminders_sent');
        $this->addSql('ALTER TABLE power_schedule_post DROP reminder_message_id');
    }
}
