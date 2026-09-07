<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record who signs in to the panel, and who tries to.
 *
 * The panel holds the whole house — who lives where, every phone, the arrears — and four
 * people share four logins, one of which was handed over in a Telegram message. The only
 * record of anybody opening it was nginx's access log, which the people who share the
 * panel cannot read.
 */
final class Version20260907180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_login';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_login (
            id SERIAL NOT NULL,
            login VARCHAR(180) NOT NULL,
            success BOOLEAN NOT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            agent VARCHAR(120) DEFAULT NULL,
            at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_admin_login_at ON admin_login (at)');
        $this->addSql("COMMENT ON COLUMN admin_login.at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_login');
    }
}
