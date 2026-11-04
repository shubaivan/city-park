<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sms_log: what TurboSMS last said about delivery. Older rows stay NULL: nobody asked';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sms_log ADD delivery_status VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE sms_log ADD delivery_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sms_log DROP delivery_status');
        $this->addSql('ALTER TABLE sms_log DROP delivery_checked_at');
    }
}
