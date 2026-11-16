<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cleaning_comment: messages from the «Прибирання» topic, relayed anonymously to the cleaning crew';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cleaning_comment (
            id SERIAL NOT NULL,
            chat_message_id INT NOT NULL,
            media_group_id VARCHAR(64) DEFAULT NULL,
            text TEXT DEFAULT NULL,
            file_id VARCHAR(255) DEFAULT NULL,
            report_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            relayed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX cc_message_uniq ON cleaning_comment (chat_message_id)');
        $this->addSql('CREATE INDEX cc_relayed_idx ON cleaning_comment (relayed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cleaning_comment');
    }
}
