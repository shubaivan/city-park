<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photos of the work that was done, kept apart from the author's photos of the problem.
 *
 * One array would have been less code and the wrong data: the value of these is «було /
 * стало», and a single list puts the repaired lift and the broken one next to each other
 * with nothing saying which is which.
 */
final class Version20260907200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add complaint.result_photos and complaint.photo_token_target';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE complaint ADD result_photos JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE complaint ADD photo_token_target VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint DROP result_photos');
        $this->addSql('ALTER TABLE complaint DROP photo_token_target');
    }
}
