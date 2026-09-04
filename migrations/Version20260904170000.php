<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `telegram_user.full_name` — ПІБ as the ОСББ's registry spells it.
 *
 * The bot only ever knew what Telegram reports, which is whatever the person chose to call
 * themselves: «63691», «Я Знову Я», «Daniil 🌍🌍🌍». That name is genuinely useful — it is
 * how the accountant recognises who is writing in the chat — but it does not match a
 * квитанція, and matching a квитанція is most of her job.
 *
 * So both are kept, in separate columns. Left NULL for everyone rather than guessed: this
 * is the column the accountant's registry file will land in, and a name invented today
 * would have to be un-invented then.
 */
final class Version20260904170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telegram_user.full_name (ПІБ from the ОСББ registry)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD full_name VARCHAR(180) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_telegram_user_full_name ON telegram_user (full_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_telegram_user_full_name');
        $this->addSql('ALTER TABLE telegram_user DROP full_name');
    }
}
