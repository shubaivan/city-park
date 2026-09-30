<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * sms_log: what the flat owed when the SMS went, and the date of that figure.
 *
 * The rows already written carry no account when they were sent by hand; they are
 * matched to a linked resident by the same nine digits PhoneKey uses, and given the debt
 * as it stands now — which for rows written today is the debt they were sent against.
 */
final class Version20260930180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sms_log: debt and debt_as_of snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sms_log ADD debt NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE sms_log ADD debt_as_of DATE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN sms_log.debt_as_of IS '(DC2Type:date_immutable)'");

        $this->addSql(<<<'SQL'
            UPDATE sms_log s SET account_id = tu.account_id, user_id = COALESCE(s.user_id, tu.id)
            FROM (
                SELECT DISTINCT ON (right(regexp_replace(phone_number, '\D', '', 'g'), 9))
                       id, account_id, right(regexp_replace(phone_number, '\D', '', 'g'), 9) AS k
                FROM telegram_user
                WHERE account_id IS NOT NULL AND phone_number IS NOT NULL
                ORDER BY right(regexp_replace(phone_number, '\D', '', 'g'), 9), id
            ) tu
            WHERE s.account_id IS NULL AND tu.k = s.phone_key
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE sms_log s SET
                debt = a.debt,
                debt_as_of = a.debt_updated_at::date
            FROM account a
            WHERE a.id = s.account_id AND s.debt IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sms_log DROP debt');
        $this->addSql('ALTER TABLE sms_log DROP debt_as_of');
    }
}
