<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * X01 first slice: teacher draft homework definitions only.
 * No assignment, recipient, submission or evaluation tables.
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add homework_definitions for teacher draft homework (X01 first slice).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE homework_definitions (
            id BINARY(16) NOT NULL,
            institution_id BINARY(16) NOT NULL,
            classroom_id BINARY(16) NOT NULL,
            academic_year_id BINARY(16) NOT NULL,
            created_by_user_id BINARY(16) NOT NULL,
            created_by_membership_id BINARY(16) NOT NULL,
            title VARCHAR(150) NOT NULL,
            description MEDIUMTEXT NOT NULL,
            due_at DATETIME NOT NULL COMMENT \'UTC\',
            status VARCHAR(32) NOT NULL,
            revision_number INT UNSIGNED DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_homework_def_creator_status (created_by_user_id, status),
            INDEX idx_homework_def_institution_status (institution_id, status),
            INDEX idx_homework_def_classroom (classroom_id),
            INDEX idx_homework_def_year (academic_year_id),
            INDEX idx_homework_def_membership (created_by_membership_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE homework_definitions ADD CONSTRAINT FK_HOMEWORK_DEF_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_definitions ADD CONSTRAINT FK_HOMEWORK_DEF_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_definitions ADD CONSTRAINT FK_HOMEWORK_DEF_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_definitions ADD CONSTRAINT FK_HOMEWORK_DEF_CREATOR FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_definitions ADD CONSTRAINT FK_HOMEWORK_DEF_MEMBERSHIP FOREIGN KEY (created_by_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homework_definitions DROP FOREIGN KEY FK_HOMEWORK_DEF_INSTITUTION');
        $this->addSql('ALTER TABLE homework_definitions DROP FOREIGN KEY FK_HOMEWORK_DEF_CLASSROOM');
        $this->addSql('ALTER TABLE homework_definitions DROP FOREIGN KEY FK_HOMEWORK_DEF_YEAR');
        $this->addSql('ALTER TABLE homework_definitions DROP FOREIGN KEY FK_HOMEWORK_DEF_CREATOR');
        $this->addSql('ALTER TABLE homework_definitions DROP FOREIGN KEY FK_HOMEWORK_DEF_MEMBERSHIP');
        $this->addSql('DROP TABLE homework_definitions');
    }
}
