<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The outage schedule goes to more than one chat: each chat keeps its own queue and its
 * own post per day. Every existing row belongs to the residents' topic.
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Outage schedule: queue and daily post per target chat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE power_queue ADD target VARCHAR(16) DEFAULT 'residents' NOT NULL");
        $this->addSql('DROP INDEX power_queue_house_uniq');
        $this->addSql('CREATE UNIQUE INDEX power_queue_target_house_uniq ON power_queue (target, house)');

        $this->addSql("ALTER TABLE power_schedule_post ADD target VARCHAR(16) DEFAULT 'residents' NOT NULL");
        $this->addSql('DROP INDEX power_schedule_post_day_uniq');
        $this->addSql('CREATE UNIQUE INDEX power_schedule_post_target_day_uniq ON power_schedule_post (target, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM power_queue WHERE target <> 'residents'");
        $this->addSql('DROP INDEX power_queue_target_house_uniq');
        $this->addSql('CREATE UNIQUE INDEX power_queue_house_uniq ON power_queue (house)');
        $this->addSql('ALTER TABLE power_queue DROP target');

        $this->addSql("DELETE FROM power_schedule_post WHERE target <> 'residents'");
        $this->addSql('DROP INDEX power_schedule_post_target_day_uniq');
        $this->addSql('CREATE UNIQUE INDEX power_schedule_post_day_uniq ON power_schedule_post (day)');
        $this->addSql('ALTER TABLE power_schedule_post DROP target');
    }
}
