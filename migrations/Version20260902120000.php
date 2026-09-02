<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * account.debt_updated_at — when the debt figure was last written by an import.
 *
 * The debtors' board publishes apartment numbers, so it has to be able to say how old
 * the numbers are and to fall silent when they go stale. Existing rows are backfilled
 * with the timestamp of the newest debt-import audit entry (the only surviving record
 * of when the accountant last uploaded a file); rows stay NULL if there is none, which
 * the board reads as "unknown, do not publish".
 */
final class Version20260902120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.debt_updated_at and backfill it from the last debt import';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD debt_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN account.debt_updated_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            UPDATE account
               SET debt_updated_at = (
                   SELECT MAX(created_at) FROM account_status_log WHERE source = 'debt_import'
               )
             WHERE EXISTS (
                   SELECT 1 FROM account_status_log WHERE source = 'debt_import'
               )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP debt_updated_at');
    }
}
