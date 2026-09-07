<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A listing is either «здається» or «продається».
 *
 * One column rather than a second entity: the whole life of the advert is identical —
 * 30 days, the «ще актуально?» prompt, up to three photos, the phone consent, the admin
 * take-down — and what differs is one word in the text and whether the price is per
 * month. Everything already published is rent, which is what the default says.
 */
final class Version20260907140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rental_listing.deal (rent | sale) and chat_message_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE rental_listing ADD deal VARCHAR(16) DEFAULT 'rent' NOT NULL");
        $this->addSql('ALTER TABLE rental_listing ADD chat_message_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rental_listing DROP deal');
        $this->addSql('ALTER TABLE rental_listing DROP chat_message_id');
    }
}
