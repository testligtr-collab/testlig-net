<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * X01 assign slice: one assignment + recipient snapshot per homework definition.
 * No submission/evaluation tables. Existing drafts stay drafts (no backfill).
 */
final class Version20261103180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add homework_assignments and homework_recipients for teacher classroom assign (X01).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE homework_assignments (
            id BINARY(16) NOT NULL,
            definition_id BINARY(16) NOT NULL,
            institution_id BINARY(16) NOT NULL,
            classroom_id BINARY(16) NOT NULL,
            academic_year_id BINARY(16) NOT NULL,
            assigned_by_user_id BINARY(16) NOT NULL,
            assigned_by_membership_id BINARY(16) NOT NULL,
            title VARCHAR(150) NOT NULL,
            description MEDIUMTEXT NOT NULL,
            due_at DATETIME NOT NULL COMMENT \'UTC\',
            status VARCHAR(32) NOT NULL,
            recipient_count INT UNSIGNED NOT NULL,
            assigned_at DATETIME NOT NULL COMMENT \'UTC\',
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_homework_assignment_definition (definition_id),
            INDEX idx_homework_asg_classroom (classroom_id),
            INDEX idx_homework_asg_institution (institution_id),
            INDEX idx_homework_asg_year (academic_year_id),
            INDEX idx_homework_asg_assigner (assigned_by_user_id),
            INDEX idx_homework_asg_membership (assigned_by_membership_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE homework_recipients (
            id BINARY(16) NOT NULL,
            assignment_id BINARY(16) NOT NULL,
            student_user_id BINARY(16) NOT NULL,
            student_membership_id BINARY(16) NOT NULL,
            enrollment_id BINARY(16) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_homework_recipient_assignment_student (assignment_id, student_user_id),
            INDEX idx_homework_rcp_student (student_user_id),
            INDEX idx_homework_rcp_membership (student_membership_id),
            INDEX idx_homework_rcp_enrollment (enrollment_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE homework_assignments ADD CONSTRAINT FK_HOMEWORK_ASG_DEFINITION FOREIGN KEY (definition_id) REFERENCES homework_definitions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_assignments ADD CONSTRAINT FK_HOMEWORK_ASG_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_assignments ADD CONSTRAINT FK_HOMEWORK_ASG_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_assignments ADD CONSTRAINT FK_HOMEWORK_ASG_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_assignments ADD CONSTRAINT FK_HOMEWORK_ASG_ASSIGNER FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_assignments ADD CONSTRAINT FK_HOMEWORK_ASG_MEMBERSHIP FOREIGN KEY (assigned_by_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_recipients ADD CONSTRAINT FK_HOMEWORK_RCP_ASSIGNMENT FOREIGN KEY (assignment_id) REFERENCES homework_assignments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_recipients ADD CONSTRAINT FK_HOMEWORK_RCP_STUDENT FOREIGN KEY (student_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_recipients ADD CONSTRAINT FK_HOMEWORK_RCP_MEMBERSHIP FOREIGN KEY (student_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_recipients ADD CONSTRAINT FK_HOMEWORK_RCP_ENROLLMENT FOREIGN KEY (enrollment_id) REFERENCES classroom_student_enrollments (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homework_recipients DROP FOREIGN KEY FK_HOMEWORK_RCP_ASSIGNMENT');
        $this->addSql('ALTER TABLE homework_recipients DROP FOREIGN KEY FK_HOMEWORK_RCP_STUDENT');
        $this->addSql('ALTER TABLE homework_recipients DROP FOREIGN KEY FK_HOMEWORK_RCP_MEMBERSHIP');
        $this->addSql('ALTER TABLE homework_recipients DROP FOREIGN KEY FK_HOMEWORK_RCP_ENROLLMENT');
        $this->addSql('ALTER TABLE homework_assignments DROP FOREIGN KEY FK_HOMEWORK_ASG_DEFINITION');
        $this->addSql('ALTER TABLE homework_assignments DROP FOREIGN KEY FK_HOMEWORK_ASG_INSTITUTION');
        $this->addSql('ALTER TABLE homework_assignments DROP FOREIGN KEY FK_HOMEWORK_ASG_CLASSROOM');
        $this->addSql('ALTER TABLE homework_assignments DROP FOREIGN KEY FK_HOMEWORK_ASG_YEAR');
        $this->addSql('ALTER TABLE homework_assignments DROP FOREIGN KEY FK_HOMEWORK_ASG_ASSIGNER');
        $this->addSql('ALTER TABLE homework_assignments DROP FOREIGN KEY FK_HOMEWORK_ASG_MEMBERSHIP');
        $this->addSql('DROP TABLE homework_recipients');
        $this->addSql('DROP TABLE homework_assignments');
    }
}
