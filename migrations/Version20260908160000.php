<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Institutions and scoped memberships for multi-tenant authorization.
 */
final class Version20260908160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create institutions and institution_memberships tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE institutions (id BINARY(16) NOT NULL, name VARCHAR(180) NOT NULL, normalized_name VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, locale VARCHAR(16) NOT NULL, timezone VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_institutions_slug (slug), INDEX idx_institutions_status (status), INDEX idx_institutions_type_status (type, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE institution_memberships (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, role VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, joined_at DATETIME DEFAULT NULL, suspended_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_institution_membership_user (institution_id, user_id), INDEX idx_membership_institution_status (institution_id, status), INDEX idx_membership_user_status (user_id, status), INDEX idx_membership_institution_role_status (institution_id, role, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE institution_memberships ADD CONSTRAINT FK_INST_MEMBERSHIP_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_memberships ADD CONSTRAINT FK_INST_MEMBERSHIP_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institution_memberships DROP FOREIGN KEY FK_INST_MEMBERSHIP_INSTITUTION');
        $this->addSql('ALTER TABLE institution_memberships DROP FOREIGN KEY FK_INST_MEMBERSHIP_USER');
        $this->addSql('DROP TABLE institution_memberships');
        $this->addSql('DROP TABLE institutions');
    }
}
