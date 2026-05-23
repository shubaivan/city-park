<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add owner_group_id on account for admin-linked owner-group merging (apartment + parking same owner share booking limits and debt)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD owner_group_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_account_owner_group ON account (owner_group_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_account_owner_group');
        $this->addSql('ALTER TABLE account DROP owner_group_id');
    }
}
