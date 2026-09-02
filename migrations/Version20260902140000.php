<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * debt_snapshot — one row per debt import.
 *
 * Debt figures are overwritten in place, so the house has no memory of its own arrears;
 * without this the chat announcement can say what is owed but not whether that is better
 * or worse than last month. `announced_at` doubles as the post's own log, so a corrected
 * re-upload does not put a second list of debtors in front of everyone.
 */
final class Version20260902140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add debt_snapshot: per-import totals and when the residents chat was told';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE debt_snapshot_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE debt_snapshot (
                id INT NOT NULL,
                taken_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                total NUMERIC(12, 2) NOT NULL,
                debtors INT NOT NULL,
                announced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_debt_snapshot_taken_at ON debt_snapshot (taken_at)');
        $this->addSql("COMMENT ON COLUMN debt_snapshot.taken_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN debt_snapshot.announced_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE debt_snapshot_id_seq CASCADE');
        $this->addSql('DROP TABLE debt_snapshot');
    }
}
