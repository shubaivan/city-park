<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Who followed a link out of the residents' chat, and into what.
 *
 * Telegram will not answer «did anybody look at this»: the read list of a message is shown
 * only to whoever sent it, these are sent by the bot, and the Bot API has no read-receipt
 * method at all. A click is therefore the only signal available — and a better one, since
 * «seen» means somebody scrolled past while a tap means they wanted the thing.
 *
 * Only links are recorded. Opening the same card from inside the bot writes nothing: the
 * question is «did the chat post work», not «what is this resident reading».
 */
final class Version20260908160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create link_click — taps on «Відкрити в боті» under a chat post';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE link_click (
            id SERIAL NOT NULL,
            user_id INT DEFAULT NULL,
            kind VARCHAR(16) NOT NULL,
            target_id INT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX lc_kind_target_idx ON link_click (kind, target_id)');
        $this->addSql('CREATE INDEX lc_created_idx ON link_click (created_at)');
        $this->addSql('CREATE INDEX IDX_LINK_CLICK_USER ON link_click (user_id)');

        // SET NULL: a click is a fact about the post and must survive the person being
        // unlinked, removed, or leaving the house.
        $this->addSql('ALTER TABLE link_click ADD CONSTRAINT FK_LINK_CLICK_USER
            FOREIGN KEY (user_id) REFERENCES telegram_user (id) ON DELETE SET NULL
            NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE link_click');
    }
}
