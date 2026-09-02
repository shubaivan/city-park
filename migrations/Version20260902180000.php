<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * complaint — the house's problem register (dead lift, burst hose, parking gate).
 *
 * Nothing is ever deleted here: a finished complaint stays with status 'done', which is
 * the house's record of what was fixed and when.
 */
final class Version20260902180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add complaint: resident-filed house problems with status and photos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE complaint_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE complaint (
                id INT NOT NULL,
                account_id INT NOT NULL,
                author_id INT DEFAULT NULL,
                text TEXT NOT NULL,
                status VARCHAR(20) NOT NULL,
                photos JSON NOT NULL,
                photo_token VARCHAR(64) DEFAULT NULL,
                photo_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status_changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status_changed_by VARCHAR(120) DEFAULT NULL,
                resolution TEXT DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_complaint_status ON complaint (status)');
        $this->addSql('CREATE INDEX IDX_complaint_account ON complaint (account_id)');
        $this->addSql('CREATE INDEX IDX_complaint_author ON complaint (author_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_complaint_photo_token ON complaint (photo_token)');

        $this->addSql("COMMENT ON COLUMN complaint.photo_token_expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN complaint.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN complaint.status_changed_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE complaint ADD CONSTRAINT FK_complaint_account FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE complaint ADD CONSTRAINT FK_complaint_author FOREIGN KEY (author_id) REFERENCES telegram_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE complaint_id_seq CASCADE');
        $this->addSql('DROP TABLE complaint');
    }
}
