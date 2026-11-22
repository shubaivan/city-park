<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'admin_action_log: «Журнал дій» — who did what in the panel, the bot and the console';
    }

    public function up(Schema $schema): void
    {
        // SERIAL rather than the bare sequence the ORM would create: rows are written with a
        // plain DBAL insert (AdminActionJournal), which needs the id to default by itself.
        $this->addSql("CREATE TABLE admin_action_log (id SERIAL NOT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, kind VARCHAR(16) DEFAULT 'change' NOT NULL, actor VARCHAR(80) DEFAULT '' NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(120) DEFAULT NULL, route VARCHAR(80) DEFAULT NULL, method VARCHAR(8) DEFAULT NULL, path VARCHAR(255) DEFAULT NULL, target_type VARCHAR(32) DEFAULT NULL, target_id INT DEFAULT NULL, account_id INT DEFAULT NULL, prev_account_id INT DEFAULT NULL, user_id INT DEFAULT NULL, summary TEXT DEFAULT '' NOT NULL, changes JSON DEFAULT NULL, status INT DEFAULT NULL, request_id VARCHAR(16) DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX aal_at_idx ON admin_action_log (at)');
        $this->addSql('CREATE INDEX aal_account_idx ON admin_action_log (account_id)');
        $this->addSql('CREATE INDEX aal_prev_account_idx ON admin_action_log (prev_account_id)');
        $this->addSql('CREATE INDEX aal_user_idx ON admin_action_log (user_id)');
        $this->addSql('CREATE INDEX aal_target_idx ON admin_action_log (target_type, target_id)');
        $this->addSql('CREATE INDEX aal_actor_idx ON admin_action_log (actor)');
        $this->addSql('CREATE INDEX aal_request_idx ON admin_action_log (request_id)');
        $this->addSql("COMMENT ON COLUMN admin_action_log.at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON TABLE admin_action_log IS 'Журнал дій: who changed what (panel request, field change, manual SQL)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_action_log');
    }
}
