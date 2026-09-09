<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Academic year, classroom, teacher assignment, and student enrollment domain
 * with composite UNIQUE indexes + composite FKs for tenant/guard consistency.
 */
final class Version20260908200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create academic year and classroom domain tables with composite tenant/guard FK guarantees';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_membership_id_institution ON institution_memberships (id, institution_id)');

        $this->addSql('CREATE TABLE academic_years (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, name VARCHAR(180) NOT NULL, normalized_name VARCHAR(180) NOT NULL, starts_at DATE NOT NULL, ends_at DATE NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_academic_year_institution_normalized_name (institution_id, normalized_name), UNIQUE INDEX uniq_academic_year_id_institution (id, institution_id), INDEX idx_academic_year_institution_status (institution_id, status), INDEX idx_academic_year_institution_dates (institution_id, starts_at, ends_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE institution_active_academic_year_guards (institution_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_active_academic_year_id (academic_year_id), PRIMARY KEY (institution_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classrooms (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, name VARCHAR(180) NOT NULL, normalized_name VARCHAR(180) NOT NULL, grade_level INT NOT NULL, section_code VARCHAR(32) DEFAULT NULL, status VARCHAR(32) NOT NULL, capacity INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_classroom_year_normalized_name (academic_year_id, normalized_name), UNIQUE INDEX uniq_classroom_id_institution (id, institution_id), UNIQUE INDEX uniq_classroom_id_year (id, academic_year_id), UNIQUE INDEX uniq_classroom_id_institution_year (id, academic_year_id, institution_id), INDEX idx_classroom_institution_status (institution_id, status), INDEX idx_classroom_year_status (academic_year_id, status), INDEX idx_classroom_institution_year (institution_id, academic_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classroom_teacher_assignments (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, classroom_id BINARY(16) NOT NULL, teacher_membership_id BINARY(16) NOT NULL, role VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, assigned_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_cta_id_classroom (id, classroom_id), UNIQUE INDEX uniq_cta_id_classroom_membership (id, classroom_id, teacher_membership_id), INDEX idx_cta_classroom_status (classroom_id, status), INDEX idx_cta_membership_status (teacher_membership_id, status), INDEX idx_cta_classroom_role_status (classroom_id, role, status), INDEX idx_cta_institution (institution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classroom_homeroom_guards (classroom_id BINARY(16) NOT NULL, assignment_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_classroom_homeroom_assignment (assignment_id), PRIMARY KEY (classroom_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classroom_teacher_active_guards (classroom_id BINARY(16) NOT NULL, teacher_membership_id BINARY(16) NOT NULL, assignment_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_classroom_teacher_active_assignment (assignment_id), PRIMARY KEY (classroom_id, teacher_membership_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classroom_student_enrollments (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, classroom_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, student_membership_id BINARY(16) NOT NULL, status VARCHAR(32) NOT NULL, enrolled_at DATETIME NOT NULL, transferred_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_cse_id_classroom_year (id, classroom_id, academic_year_id), UNIQUE INDEX uniq_cse_id_year_membership (id, academic_year_id, student_membership_id), UNIQUE INDEX uniq_cse_id_classroom_year_membership (id, classroom_id, academic_year_id, student_membership_id), INDEX idx_cse_classroom_status (classroom_id, status), INDEX idx_cse_year_membership_status (academic_year_id, student_membership_id, status), INDEX idx_cse_membership_status (student_membership_id, status), INDEX idx_cse_institution (institution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE academic_year_student_enrollment_guards (academic_year_id BINARY(16) NOT NULL, student_membership_id BINARY(16) NOT NULL, enrollment_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_ay_student_enrollment (enrollment_id), PRIMARY KEY (academic_year_id, student_membership_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE academic_years ADD CONSTRAINT FK_ACADEMIC_YEAR_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE institution_active_academic_year_guards ADD CONSTRAINT FK_ACTIVE_AY_GUARD_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_active_academic_year_guards ADD CONSTRAINT FK_ACTIVE_AY_GUARD_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_active_academic_year_guards ADD CONSTRAINT FK_ACTIVE_AY_GUARD_YEAR_INSTITUTION FOREIGN KEY (academic_year_id, institution_id) REFERENCES academic_years (id, institution_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE classrooms ADD CONSTRAINT FK_CLASSROOM_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classrooms ADD CONSTRAINT FK_CLASSROOM_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classrooms ADD CONSTRAINT FK_CLASSROOM_YEAR_INSTITUTION FOREIGN KEY (academic_year_id, institution_id) REFERENCES academic_years (id, institution_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE classroom_teacher_assignments ADD CONSTRAINT FK_CTA_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_teacher_assignments ADD CONSTRAINT FK_CTA_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_teacher_assignments ADD CONSTRAINT FK_CTA_MEMBERSHIP FOREIGN KEY (teacher_membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_teacher_assignments ADD CONSTRAINT FK_CTA_CLASSROOM_INSTITUTION FOREIGN KEY (classroom_id, institution_id) REFERENCES classrooms (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_teacher_assignments ADD CONSTRAINT FK_CTA_MEMBERSHIP_INSTITUTION FOREIGN KEY (teacher_membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE classroom_homeroom_guards ADD CONSTRAINT FK_HOMEROOM_GUARD_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_homeroom_guards ADD CONSTRAINT FK_HOMEROOM_GUARD_ASSIGNMENT FOREIGN KEY (assignment_id) REFERENCES classroom_teacher_assignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_homeroom_guards ADD CONSTRAINT FK_HOMEROOM_GUARD_ASSIGNMENT_CLASSROOM FOREIGN KEY (assignment_id, classroom_id) REFERENCES classroom_teacher_assignments (id, classroom_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE classroom_teacher_active_guards ADD CONSTRAINT FK_CTA_ACTIVE_GUARD_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards ADD CONSTRAINT FK_CTA_ACTIVE_GUARD_MEMBERSHIP FOREIGN KEY (teacher_membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards ADD CONSTRAINT FK_CTA_ACTIVE_GUARD_ASSIGNMENT FOREIGN KEY (assignment_id) REFERENCES classroom_teacher_assignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards ADD CONSTRAINT FK_CTA_ACTIVE_GUARD_ASSIGNMENT_KEYS FOREIGN KEY (assignment_id, classroom_id, teacher_membership_id) REFERENCES classroom_teacher_assignments (id, classroom_id, teacher_membership_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_MEMBERSHIP FOREIGN KEY (student_membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_CLASSROOM_YEAR FOREIGN KEY (classroom_id, academic_year_id) REFERENCES classrooms (id, academic_year_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_CLASSROOM_INSTITUTION FOREIGN KEY (classroom_id, institution_id) REFERENCES classrooms (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_YEAR_INSTITUTION FOREIGN KEY (academic_year_id, institution_id) REFERENCES academic_years (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_student_enrollments ADD CONSTRAINT FK_CSE_MEMBERSHIP_INSTITUTION FOREIGN KEY (student_membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards ADD CONSTRAINT FK_AY_ENROLL_GUARD_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards ADD CONSTRAINT FK_AY_ENROLL_GUARD_MEMBERSHIP FOREIGN KEY (student_membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards ADD CONSTRAINT FK_AY_ENROLL_GUARD_ENROLLMENT FOREIGN KEY (enrollment_id) REFERENCES classroom_student_enrollments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards ADD CONSTRAINT FK_AY_ENROLL_GUARD_ENROLLMENT_KEYS FOREIGN KEY (enrollment_id, academic_year_id, student_membership_id) REFERENCES classroom_student_enrollments (id, academic_year_id, student_membership_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards RENAME INDEX FK_AY_ENROLL_GUARD_MEMBERSHIP TO IDX_C46C780D4A2FAC53');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards RENAME INDEX FK_CTA_ACTIVE_GUARD_MEMBERSHIP TO IDX_AA21C057D7F2DAB9');
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards RENAME INDEX FK_AY_ENROLL_GUARD_ENROLLMENT_KEYS TO IDX_C46C780D8F7DB25BC54F34014A2FAC53');
        $this->addSql('ALTER TABLE classrooms RENAME INDEX FK_CLASSROOM_YEAR_INSTITUTION TO IDX_95F95DC2C54F340110405986');
        $this->addSql('ALTER TABLE classroom_homeroom_guards RENAME INDEX FK_HOMEROOM_GUARD_ASSIGNMENT_CLASSROOM TO IDX_BFD8BB99D19302F86278D5A8');
        $this->addSql('ALTER TABLE classroom_student_enrollments RENAME INDEX FK_CSE_CLASSROOM_YEAR TO IDX_69D7FFF16278D5A8C54F3401');
        $this->addSql('ALTER TABLE classroom_student_enrollments RENAME INDEX FK_CSE_CLASSROOM_INSTITUTION TO IDX_69D7FFF16278D5A810405986');
        $this->addSql('ALTER TABLE classroom_student_enrollments RENAME INDEX FK_CSE_YEAR_INSTITUTION TO IDX_69D7FFF1C54F340110405986');
        $this->addSql('ALTER TABLE classroom_student_enrollments RENAME INDEX FK_CSE_MEMBERSHIP_INSTITUTION TO IDX_69D7FFF14A2FAC5310405986');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards RENAME INDEX FK_CTA_ACTIVE_GUARD_ASSIGNMENT_KEYS TO IDX_AA21C057D19302F86278D5A8D7F2DAB9');
        $this->addSql('ALTER TABLE classroom_teacher_assignments RENAME INDEX FK_CTA_CLASSROOM_INSTITUTION TO IDX_9439DF576278D5A810405986');
        $this->addSql('ALTER TABLE classroom_teacher_assignments RENAME INDEX FK_CTA_MEMBERSHIP_INSTITUTION TO IDX_9439DF57D7F2DAB910405986');
        $this->addSql('ALTER TABLE institution_active_academic_year_guards RENAME INDEX FK_ACTIVE_AY_GUARD_YEAR_INSTITUTION TO IDX_F3D195ABC54F340110405986');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards DROP FOREIGN KEY FK_AY_ENROLL_GUARD_YEAR');
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards DROP FOREIGN KEY FK_AY_ENROLL_GUARD_MEMBERSHIP');
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards DROP FOREIGN KEY FK_AY_ENROLL_GUARD_ENROLLMENT');
        $this->addSql('ALTER TABLE academic_year_student_enrollment_guards DROP FOREIGN KEY FK_AY_ENROLL_GUARD_ENROLLMENT_KEYS');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_CLASSROOM');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_ACADEMIC_YEAR');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_MEMBERSHIP');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_CLASSROOM_YEAR');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_CLASSROOM_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_YEAR_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_student_enrollments DROP FOREIGN KEY FK_CSE_MEMBERSHIP_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards DROP FOREIGN KEY FK_CTA_ACTIVE_GUARD_CLASSROOM');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards DROP FOREIGN KEY FK_CTA_ACTIVE_GUARD_MEMBERSHIP');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards DROP FOREIGN KEY FK_CTA_ACTIVE_GUARD_ASSIGNMENT');
        $this->addSql('ALTER TABLE classroom_teacher_active_guards DROP FOREIGN KEY FK_CTA_ACTIVE_GUARD_ASSIGNMENT_KEYS');
        $this->addSql('ALTER TABLE classroom_homeroom_guards DROP FOREIGN KEY FK_HOMEROOM_GUARD_CLASSROOM');
        $this->addSql('ALTER TABLE classroom_homeroom_guards DROP FOREIGN KEY FK_HOMEROOM_GUARD_ASSIGNMENT');
        $this->addSql('ALTER TABLE classroom_homeroom_guards DROP FOREIGN KEY FK_HOMEROOM_GUARD_ASSIGNMENT_CLASSROOM');
        $this->addSql('ALTER TABLE classroom_teacher_assignments DROP FOREIGN KEY FK_CTA_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_teacher_assignments DROP FOREIGN KEY FK_CTA_CLASSROOM');
        $this->addSql('ALTER TABLE classroom_teacher_assignments DROP FOREIGN KEY FK_CTA_MEMBERSHIP');
        $this->addSql('ALTER TABLE classroom_teacher_assignments DROP FOREIGN KEY FK_CTA_CLASSROOM_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_teacher_assignments DROP FOREIGN KEY FK_CTA_MEMBERSHIP_INSTITUTION');
        $this->addSql('ALTER TABLE classrooms DROP FOREIGN KEY FK_CLASSROOM_INSTITUTION');
        $this->addSql('ALTER TABLE classrooms DROP FOREIGN KEY FK_CLASSROOM_ACADEMIC_YEAR');
        $this->addSql('ALTER TABLE classrooms DROP FOREIGN KEY FK_CLASSROOM_YEAR_INSTITUTION');
        $this->addSql('ALTER TABLE institution_active_academic_year_guards DROP FOREIGN KEY FK_ACTIVE_AY_GUARD_INSTITUTION');
        $this->addSql('ALTER TABLE institution_active_academic_year_guards DROP FOREIGN KEY FK_ACTIVE_AY_GUARD_YEAR');
        $this->addSql('ALTER TABLE institution_active_academic_year_guards DROP FOREIGN KEY FK_ACTIVE_AY_GUARD_YEAR_INSTITUTION');
        $this->addSql('ALTER TABLE academic_years DROP FOREIGN KEY FK_ACADEMIC_YEAR_INSTITUTION');
        $this->addSql('DROP TABLE academic_year_student_enrollment_guards');
        $this->addSql('DROP TABLE classroom_student_enrollments');
        $this->addSql('DROP TABLE classroom_teacher_active_guards');
        $this->addSql('DROP TABLE classroom_homeroom_guards');
        $this->addSql('DROP TABLE classroom_teacher_assignments');
        $this->addSql('DROP TABLE classrooms');
        $this->addSql('DROP TABLE institution_active_academic_year_guards');
        $this->addSql('DROP TABLE academic_years');
        $this->addSql('DROP INDEX uniq_membership_id_institution ON institution_memberships');
    }
}
