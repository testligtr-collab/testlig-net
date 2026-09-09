<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Curriculum / subject / classroom-course domain with composite tenant/guard FKs.
 */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create subject, curriculum, classroom-course, and course-teacher tables with composite FK guarantees';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subjects (id BINARY(16) NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(180) NOT NULL, normalized_name VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_subject_code (code), UNIQUE INDEX uniq_subject_slug (slug), INDEX idx_subject_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE curriculum_programs (id BINARY(16) NOT NULL, subject_id BINARY(16) NOT NULL, grade_level INT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(180) NOT NULL, normalized_name VARCHAR(180) NOT NULL, version VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, valid_from DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, published_at DATETIME DEFAULT NULL, retired_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_curriculum_subject_grade_code_version (subject_id, grade_level, code, version), UNIQUE INDEX uniq_curriculum_id_subject (id, subject_id), INDEX idx_curriculum_subject_status (subject_id, status), INDEX idx_curriculum_grade_status (grade_level, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE curriculum_units (id BINARY(16) NOT NULL, curriculum_program_id BINARY(16) NOT NULL, code VARCHAR(64) NOT NULL, title VARCHAR(180) NOT NULL, normalized_title VARCHAR(180) NOT NULL, position INT NOT NULL, estimated_minutes INT DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_curriculum_unit_program_code (curriculum_program_id, code), UNIQUE INDEX uniq_curriculum_unit_program_position (curriculum_program_id, position), UNIQUE INDEX uniq_curriculum_unit_id_program (id, curriculum_program_id), INDEX idx_curriculum_unit_program_status (curriculum_program_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // position_scope_id: STORED generated IFNULL(parent_id, nil-UUID). MariaDB UNIQUE
        // treats NULL as distinct, so root sibling positions need a non-NULL scope sentinel.
        $this->addSql('CREATE TABLE curriculum_topics (id BINARY(16) NOT NULL, unit_id BINARY(16) NOT NULL, parent_id BINARY(16) DEFAULT NULL, code VARCHAR(64) NOT NULL, title VARCHAR(180) NOT NULL, normalized_title VARCHAR(180) NOT NULL, position INT NOT NULL, estimated_minutes INT DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, position_scope_id BINARY(16) AS (ifnull(`parent_id`,0x00000000000000000000000000000000)) STORED, UNIQUE INDEX uniq_curriculum_topic_unit_code (unit_id, code), UNIQUE INDEX uniq_curriculum_topic_unit_scope_position (unit_id, position_scope_id, position), UNIQUE INDEX uniq_curriculum_topic_id_unit (id, unit_id), INDEX idx_curriculum_topic_unit_status (unit_id, status), INDEX idx_curriculum_topic_parent (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE classroom_courses (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, classroom_id BINARY(16) NOT NULL, subject_id BINARY(16) NOT NULL, curriculum_program_id BINARY(16) NOT NULL, status VARCHAR(32) NOT NULL, weekly_lesson_hours INT DEFAULT NULL, archived_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_cc_id_institution (id, institution_id), UNIQUE INDEX uniq_cc_id_academic_year (id, academic_year_id), UNIQUE INDEX uniq_cc_id_classroom (id, classroom_id), UNIQUE INDEX uniq_cc_id_classroom_subject (id, classroom_id, subject_id), UNIQUE INDEX uniq_cc_id_program_subject (id, curriculum_program_id, subject_id), INDEX idx_cc_classroom_status (classroom_id, status), INDEX idx_cc_institution_status (institution_id, status), INDEX idx_cc_subject_status (subject_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE classroom_course_active_guards (classroom_id BINARY(16) NOT NULL, subject_id BINARY(16) NOT NULL, course_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_classroom_course_active_course (course_id), PRIMARY KEY (classroom_id, subject_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE course_teacher_assignments (id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, classroom_course_id BINARY(16) NOT NULL, teacher_membership_id BINARY(16) NOT NULL, status VARCHAR(32) NOT NULL, assigned_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_cteach_id_course (id, classroom_course_id), UNIQUE INDEX uniq_cteach_id_course_membership (id, classroom_course_id, teacher_membership_id), UNIQUE INDEX uniq_cteach_id_institution (id, institution_id), INDEX idx_cteach_course_status (classroom_course_id, status), INDEX idx_cteach_membership_status (teacher_membership_id, status), INDEX idx_cteach_institution (institution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE course_teacher_active_guards (classroom_course_id BINARY(16) NOT NULL, teacher_membership_id BINARY(16) NOT NULL, assignment_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_course_teacher_active_assignment (assignment_id), PRIMARY KEY (classroom_course_id, teacher_membership_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE curriculum_programs ADD CONSTRAINT FK_CURRICULUM_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE curriculum_units ADD CONSTRAINT FK_CU_UNIT_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE curriculum_topics ADD CONSTRAINT FK_TOPIC_UNIT FOREIGN KEY (unit_id) REFERENCES curriculum_units (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_topics ADD CONSTRAINT FK_TOPIC_PARENT FOREIGN KEY (parent_id) REFERENCES curriculum_topics (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE curriculum_topics ADD CONSTRAINT FK_TOPIC_PARENT_SAME_UNIT FOREIGN KEY (parent_id, unit_id) REFERENCES curriculum_topics (id, unit_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_CLASSROOM_YEAR_INSTITUTION FOREIGN KEY (classroom_id, academic_year_id, institution_id) REFERENCES classrooms (id, academic_year_id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_CLASSROOM_INSTITUTION FOREIGN KEY (classroom_id, institution_id) REFERENCES classrooms (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_YEAR_INSTITUTION FOREIGN KEY (academic_year_id, institution_id) REFERENCES academic_years (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_courses ADD CONSTRAINT FK_CC_PROGRAM_SUBJECT FOREIGN KEY (curriculum_program_id, subject_id) REFERENCES curriculum_programs (id, subject_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE classroom_course_active_guards ADD CONSTRAINT FK_CC_ACTIVE_GUARD_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_course_active_guards ADD CONSTRAINT FK_CC_ACTIVE_GUARD_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_course_active_guards ADD CONSTRAINT FK_CC_ACTIVE_GUARD_COURSE FOREIGN KEY (course_id) REFERENCES classroom_courses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_course_active_guards ADD CONSTRAINT FK_CC_ACTIVE_GUARD_COURSE_KEYS FOREIGN KEY (course_id, classroom_id, subject_id) REFERENCES classroom_courses (id, classroom_id, subject_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE course_teacher_assignments ADD CONSTRAINT FK_CTEACH_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course_teacher_assignments ADD CONSTRAINT FK_CTEACH_COURSE FOREIGN KEY (classroom_course_id) REFERENCES classroom_courses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course_teacher_assignments ADD CONSTRAINT FK_CTEACH_MEMBERSHIP FOREIGN KEY (teacher_membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course_teacher_assignments ADD CONSTRAINT FK_CTEACH_COURSE_INSTITUTION FOREIGN KEY (classroom_course_id, institution_id) REFERENCES classroom_courses (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course_teacher_assignments ADD CONSTRAINT FK_CTEACH_MEMBERSHIP_INSTITUTION FOREIGN KEY (teacher_membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE course_teacher_active_guards ADD CONSTRAINT FK_CTEACH_ACTIVE_GUARD_COURSE FOREIGN KEY (classroom_course_id) REFERENCES classroom_courses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course_teacher_active_guards ADD CONSTRAINT FK_CTEACH_ACTIVE_GUARD_MEMBERSHIP FOREIGN KEY (teacher_membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course_teacher_active_guards ADD CONSTRAINT FK_CTEACH_ACTIVE_GUARD_ASSIGNMENT FOREIGN KEY (assignment_id) REFERENCES course_teacher_assignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course_teacher_active_guards ADD CONSTRAINT FK_CTEACH_ACTIVE_GUARD_ASSIGNMENT_KEYS FOREIGN KEY (assignment_id, classroom_course_id, teacher_membership_id) REFERENCES course_teacher_assignments (id, classroom_course_id, teacher_membership_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE classroom_courses RENAME INDEX FK_CC_CLASSROOM_YEAR_INSTITUTION TO IDX_23549B646278D5A8C54F340110405986');
        $this->addSql('ALTER TABLE classroom_courses RENAME INDEX FK_CC_CLASSROOM_INSTITUTION TO IDX_23549B646278D5A810405986');
        $this->addSql('ALTER TABLE classroom_courses RENAME INDEX FK_CC_YEAR_INSTITUTION TO IDX_23549B64C54F340110405986');
        $this->addSql('ALTER TABLE classroom_courses RENAME INDEX FK_CC_PROGRAM_SUBJECT TO IDX_23549B64CBC6880023EDC87');
        $this->addSql('ALTER TABLE classroom_course_active_guards RENAME INDEX FK_CC_ACTIVE_GUARD_SUBJECT TO IDX_4ED1E3CE23EDC87');
        $this->addSql('ALTER TABLE classroom_course_active_guards RENAME INDEX FK_CC_ACTIVE_GUARD_COURSE_KEYS TO IDX_4ED1E3CE591CC9926278D5A823EDC87');
        $this->addSql('ALTER TABLE course_teacher_active_guards RENAME INDEX FK_CTEACH_ACTIVE_GUARD_MEMBERSHIP TO IDX_7B86ADB0D7F2DAB9');
        $this->addSql('ALTER TABLE course_teacher_active_guards RENAME INDEX FK_CTEACH_ACTIVE_GUARD_ASSIGNMENT_KEYS TO IDX_7B86ADB0D19302F8E8221FEFD7F2DAB9');
        $this->addSql('ALTER TABLE course_teacher_assignments RENAME INDEX FK_CTEACH_COURSE_INSTITUTION TO IDX_F24E1906E8221FEF10405986');
        $this->addSql('ALTER TABLE course_teacher_assignments RENAME INDEX FK_CTEACH_MEMBERSHIP_INSTITUTION TO IDX_F24E1906D7F2DAB910405986');
        $this->addSql('ALTER TABLE curriculum_topics RENAME INDEX FK_TOPIC_PARENT_SAME_UNIT TO IDX_7F03B6D8727ACA70F8BD700D');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE course_teacher_active_guards DROP FOREIGN KEY FK_CTEACH_ACTIVE_GUARD_COURSE');
        $this->addSql('ALTER TABLE course_teacher_active_guards DROP FOREIGN KEY FK_CTEACH_ACTIVE_GUARD_MEMBERSHIP');
        $this->addSql('ALTER TABLE course_teacher_active_guards DROP FOREIGN KEY FK_CTEACH_ACTIVE_GUARD_ASSIGNMENT');
        $this->addSql('ALTER TABLE course_teacher_active_guards DROP FOREIGN KEY FK_CTEACH_ACTIVE_GUARD_ASSIGNMENT_KEYS');
        $this->addSql('ALTER TABLE course_teacher_assignments DROP FOREIGN KEY FK_CTEACH_INSTITUTION');
        $this->addSql('ALTER TABLE course_teacher_assignments DROP FOREIGN KEY FK_CTEACH_COURSE');
        $this->addSql('ALTER TABLE course_teacher_assignments DROP FOREIGN KEY FK_CTEACH_MEMBERSHIP');
        $this->addSql('ALTER TABLE course_teacher_assignments DROP FOREIGN KEY FK_CTEACH_COURSE_INSTITUTION');
        $this->addSql('ALTER TABLE course_teacher_assignments DROP FOREIGN KEY FK_CTEACH_MEMBERSHIP_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_course_active_guards DROP FOREIGN KEY FK_CC_ACTIVE_GUARD_CLASSROOM');
        $this->addSql('ALTER TABLE classroom_course_active_guards DROP FOREIGN KEY FK_CC_ACTIVE_GUARD_SUBJECT');
        $this->addSql('ALTER TABLE classroom_course_active_guards DROP FOREIGN KEY FK_CC_ACTIVE_GUARD_COURSE');
        $this->addSql('ALTER TABLE classroom_course_active_guards DROP FOREIGN KEY FK_CC_ACTIVE_GUARD_COURSE_KEYS');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_ACADEMIC_YEAR');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_CLASSROOM');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_SUBJECT');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_PROGRAM');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_CLASSROOM_YEAR_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_CLASSROOM_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_YEAR_INSTITUTION');
        $this->addSql('ALTER TABLE classroom_courses DROP FOREIGN KEY FK_CC_PROGRAM_SUBJECT');
        $this->addSql('ALTER TABLE curriculum_topics DROP FOREIGN KEY FK_TOPIC_UNIT');
        $this->addSql('ALTER TABLE curriculum_topics DROP FOREIGN KEY FK_TOPIC_PARENT');
        $this->addSql('ALTER TABLE curriculum_topics DROP FOREIGN KEY FK_TOPIC_PARENT_SAME_UNIT');
        $this->addSql('ALTER TABLE curriculum_units DROP FOREIGN KEY FK_CU_UNIT_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_programs DROP FOREIGN KEY FK_CURRICULUM_SUBJECT');
        $this->addSql('DROP TABLE course_teacher_active_guards');
        $this->addSql('DROP TABLE course_teacher_assignments');
        $this->addSql('DROP TABLE classroom_course_active_guards');
        $this->addSql('DROP TABLE classroom_courses');
        $this->addSql('DROP TABLE curriculum_topics');
        $this->addSql('DROP TABLE curriculum_units');
        $this->addSql('DROP TABLE curriculum_programs');
        $this->addSql('DROP TABLE subjects');
    }
}
