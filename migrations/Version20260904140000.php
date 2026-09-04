<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `account.unit_type` — what kind of property this is, written down instead of recomputed.
 *
 * The type was derived from the особовий рахунок every time it was needed: the third digit
 * of Q-P-T-NNN, 0 apartment / 5 storage / 7 parking. That answer decides the label on the
 * public debtors' board, the address in a complaint posted to the residents' chat, and
 * whether the owner may book the pavilion — and it was never stored, so:
 *
 * - a mistyped rahunok read as the *wrong type* rather than as an error (`42076` is five
 *   digits; the formula reads its third character, which is the `0` of what should have
 *   been `420076`);
 * - nothing in the row said what it was — six of the eight non-flat accounts carry a bare
 *   number in `apartment_number`;
 * - and nobody could correct one, because there was nothing to correct.
 *
 * The backfill below is the formula itself, so every existing row keeps exactly the type it
 * already had — this migration changes no behaviour on the day it runs. From then on the
 * column is the answer and the formula is only the fallback for a row that has none.
 */
final class Version20260904140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.unit_type (apartment/parking/storage), seeded from the account-number formula';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD unit_type VARCHAR(16) DEFAULT NULL');

        // Mirrors Account::deriveUnitType() exactly, including the free-text storage check
        // for legacy rows that spell it out in apartment_number.
        $this->addSql(<<<'SQL'
            UPDATE account SET unit_type = CASE
                WHEN lower(coalesce(apartment_number, '')) ~ 'кладов|комірчина|комирчина|storage' THEN 'storage'
                WHEN substring(regexp_replace(coalesce(account_number, ''), '\D', '', 'g') from 3 for 1) = '5' THEN 'storage'
                WHEN substring(regexp_replace(coalesce(account_number, ''), '\D', '', 'g') from 3 for 1) = '7' THEN 'parking'
                ELSE 'apartment'
            END
        SQL);

        $this->addSql('CREATE INDEX idx_account_unit_type ON account (unit_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_account_unit_type');
        $this->addSql('ALTER TABLE account DROP unit_type');
    }
}
