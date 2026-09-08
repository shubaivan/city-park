<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "🛠 Послуги мешканців" — the house's own list of who does what.
 *
 * No category column, deliberately: `title` is free text in the resident's own words,
 * because any fixed list of trades breaks on the first плиточник. See the ServiceOffer
 * entity for the full argument, and for why the offer belongs to the person (author_id)
 * rather than to the account.
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create service_offer — residents advertising the work they do';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_offer (
            id SERIAL NOT NULL,
            account_id INT NOT NULL,
            author_id INT DEFAULT NULL,
            status VARCHAR(16) DEFAULT \'active\' NOT NULL,
            title VARCHAR(80) NOT NULL,
            description TEXT DEFAULT NULL,
            price_note VARCHAR(64) DEFAULT NULL,
            chat_message_id INT DEFAULT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            renew_prompt_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            closed_by VARCHAR(64) DEFAULT NULL,
            photos JSON DEFAULT \'[]\' NOT NULL,
            photo_token VARCHAR(32) DEFAULT NULL,
            photo_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            show_phone BOOLEAN DEFAULT false NOT NULL,
            contact_phone VARCHAR(32) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX so_status_expires_idx ON service_offer (status, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SERVICE_OFFER_PHOTO_TOKEN ON service_offer (photo_token)');
        $this->addSql('CREATE INDEX IDX_SERVICE_OFFER_ACCOUNT ON service_offer (account_id)');
        $this->addSql('CREATE INDEX IDX_SERVICE_OFFER_AUTHOR ON service_offer (author_id)');

        $this->addSql('ALTER TABLE service_offer ADD CONSTRAINT FK_SERVICE_OFFER_ACCOUNT
            FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
            NOT DEFERRABLE INITIALLY IMMEDIATE');

        // SET NULL, not CASCADE: an offer must survive its author's row being removed —
        // the account is the fallback owner, and losing the advert would silently take a
        // published card out of the list with nothing saying why.
        $this->addSql('ALTER TABLE service_offer ADD CONSTRAINT FK_SERVICE_OFFER_AUTHOR
            FOREIGN KEY (author_id) REFERENCES telegram_user (id) ON DELETE SET NULL
            NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_offer');
    }
}
