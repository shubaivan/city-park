<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The official discussion under a complaint, plus the ⏸ «відкладено» status it explains.
 *
 * The register told the house *that* something was happening. It gave the head of the ОСББ
 * no way to ask the one question that unblocks most reports («яка саме секція?»), and no
 * honest way to record the most common real state of a house problem: known, agreed, and
 * waiting on a part, a contractor or the money. Both were being handled outside the bot,
 * where the house could not see them.
 *
 * ON DELETE CASCADE, not a Doctrine cascade: `complaint:cleanup` removes complaints in
 * bulk, and a thread outliving its complaint is an orphan nothing can ever render.
 */
final class Version20260904100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add complaint_comment (official discussion thread)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE complaint_comment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE complaint_comment (
                id INT NOT NULL,
                complaint_id INT NOT NULL,
                author_id INT DEFAULT NULL,
                author_label VARCHAR(120) NOT NULL,
                official BOOLEAN NOT NULL,
                text TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_complaint_comment_thread ON complaint_comment (complaint_id, created_at)');
        $this->addSql('CREATE INDEX IDX_complaint_comment_author ON complaint_comment (author_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE complaint_comment
                ADD CONSTRAINT FK_complaint_comment_complaint
                FOREIGN KEY (complaint_id) REFERENCES complaint (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE complaint_comment
                ADD CONSTRAINT FK_complaint_comment_author
                FOREIGN KEY (author_id) REFERENCES telegram_user (id)
                ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql("COMMENT ON COLUMN complaint_comment.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE complaint_comment');
        $this->addSql('DROP SEQUENCE complaint_comment_id_seq');
    }
}
