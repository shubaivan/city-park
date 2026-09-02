<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * telegram_user.role — owner / family / tenant, set by the accountant.
 *
 * Deliberately left NULL for everyone rather than seeded with a guess. The obvious
 * heuristic — "the only person linked to a flat is its owner" — would be right most of
 * the time and confidently wrong for every flat where a tenant registered first, and a
 * field that is wrong in an unknown subset is worse than one that is honestly empty.
 * "Не вказано" is a state the accountant can work through; a wrong "Власник" is not.
 */
final class Version20260902220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telegram_user.role (owner/family/tenant)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_user ADD role VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_telegram_user_role ON telegram_user (role)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_telegram_user_role');
        $this->addSql('ALTER TABLE telegram_user DROP role');
    }
}
