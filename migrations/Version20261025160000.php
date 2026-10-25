<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Empty institution branch table. Existing institutions and memberships are unchanged.
 */
final class Version20261025160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the institution branch table with a required institution foreign key.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE institution_branches (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, name VARCHAR(180) NOT NULL, normalized_name VARCHAR(180) NOT NULL, code VARCHAR(32) NOT NULL, normalized_code VARCHAR(32) NOT NULL, kind VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_C905DDF110405986 (institution_id), INDEX idx_institution_branches_status (status), INDEX idx_institution_branches_institution_status (institution_id, status), UNIQUE INDEX uniq_institution_branch_code (institution_id, normalized_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE institution_branches ADD CONSTRAINT FK_C905DDF110405986 FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institution_branches DROP FOREIGN KEY FK_C905DDF110405986');
        $this->addSql('DROP TABLE institution_branches');
    }
}
