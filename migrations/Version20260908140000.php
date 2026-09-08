<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The trade board keeps two facts: what the person does, and the number to ring.
 *
 * `price_note` and `description` were asked for on the day the board shipped and came back
 * out the same day. A price written a month earlier into a classified is a guess or a
 * promise nobody meant to make — the figure is settled between two people once one of them
 * has said what needs doing — and every extra step in the publish flow is a reason to close
 * the bot and write in the house chat instead. Photos say more about a плиточник than the
 * paragraph did.
 *
 * `show_phone` goes because it asked the wrong question. It was a consent toggle over the
 * registry's own number, and the board now takes any number: «я хочу розмістити телефон
 * свого друга електрика». `contact_phone` alone says everything — a number, or none.
 *
 * Safe to run: prod held zero offers when this shipped.
 */
final class Version20260908140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_offer keeps only the trade and a phone: drop price_note, description, show_phone';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_offer DROP COLUMN price_note');
        $this->addSql('ALTER TABLE service_offer DROP COLUMN description');
        $this->addSql('ALTER TABLE service_offer DROP COLUMN show_phone');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_offer ADD price_note VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_offer ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_offer ADD show_phone BOOLEAN DEFAULT false NOT NULL');
    }
}
