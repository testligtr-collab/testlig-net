<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.8: learning outcomes + versioned question bank with composite FK / CHECK / primary-alignment guards.
 */
final class Version20260909180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create curriculum learning outcomes and secure versioned question bank tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE curriculum_learning_outcomes (id BINARY(16) NOT NULL, topic_id BINARY(16) NOT NULL, unit_id BINARY(16) NOT NULL, curriculum_program_id BINARY(16) NOT NULL, code VARCHAR(64) NOT NULL, description VARCHAR(500) NOT NULL, normalized_description VARCHAR(500) NOT NULL, position INT NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_clo_program_code (curriculum_program_id, code), UNIQUE INDEX uniq_clo_topic_position (topic_id, position), UNIQUE INDEX uniq_clo_id_topic (id, topic_id), UNIQUE INDEX uniq_clo_id_unit (id, unit_id), UNIQUE INDEX uniq_clo_id_program (id, curriculum_program_id), UNIQUE INDEX uniq_clo_id_topic_program (id, topic_id, curriculum_program_id), UNIQUE INDEX uniq_clo_id_topic_unit_program (id, topic_id, unit_id, curriculum_program_id), INDEX idx_clo_topic_status (topic_id, status), INDEX idx_clo_program_status (curriculum_program_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE questions (id BINARY(16) NOT NULL, scope VARCHAR(32) NOT NULL, institution_id BINARY(16) DEFAULT NULL, subject_id BINARY(16) NOT NULL, grade_level INT NOT NULL, created_by_id BINARY(16) NOT NULL, status VARCHAR(32) NOT NULL, current_revision_number INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_question_id_subject (id, subject_id), UNIQUE INDEX uniq_question_id_institution (id, institution_id), UNIQUE INDEX uniq_question_id_scope (id, scope), INDEX idx_question_scope_status (scope, status), INDEX idx_question_institution_status (institution_id, status), INDEX idx_question_subject_grade (subject_id, grade_level), INDEX idx_question_created_by (created_by_id), PRIMARY KEY (id), CONSTRAINT chk_question_scope_institution CHECK (((scope = \'platform\' AND institution_id IS NULL) OR (scope = \'institution\' AND institution_id IS NOT NULL)))) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE question_revisions (id BINARY(16) NOT NULL, question_id BINARY(16) NOT NULL, revision_number INT NOT NULL, type VARCHAR(32) NOT NULL, stem_content JSON NOT NULL, explanation_content JSON DEFAULT NULL, difficulty VARCHAR(32) NOT NULL, estimated_seconds INT DEFAULT NULL, source_type VARCHAR(32) NOT NULL, source_reference VARCHAR(255) DEFAULT NULL, created_by_id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, content_hash VARCHAR(64) NOT NULL, schema_version INT NOT NULL, UNIQUE INDEX uniq_question_revision_number (question_id, revision_number), UNIQUE INDEX uniq_question_revision_id_question (id, question_id), INDEX idx_question_revision_question (question_id), INDEX idx_question_revision_created_by (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE question_revision_options (id BINARY(16) NOT NULL, revision_id BINARY(16) NOT NULL, stable_key VARCHAR(32) NOT NULL, content JSON NOT NULL, position INT NOT NULL, UNIQUE INDEX uniq_qro_revision_stable_key (revision_id, stable_key), UNIQUE INDEX uniq_qro_revision_position (revision_id, position), INDEX idx_qro_revision (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE question_answer_keys (id BINARY(16) NOT NULL, revision_id BINARY(16) NOT NULL, answer_type VARCHAR(32) NOT NULL, answer_payload JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_qak_revision (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE question_revision_alignments (id BINARY(16) NOT NULL, revision_id BINARY(16) NOT NULL, curriculum_program_id BINARY(16) NOT NULL, subject_id BINARY(16) NOT NULL, curriculum_topic_id BINARY(16) NOT NULL, learning_outcome_id BINARY(16) NOT NULL, is_primary TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_qra_revision_outcome (revision_id, learning_outcome_id), UNIQUE INDEX uniq_qra_id_revision (id, revision_id), INDEX idx_qra_revision (revision_id), INDEX idx_qra_outcome (learning_outcome_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE question_revision_primary_alignment_guards (revision_id BINARY(16) NOT NULL, alignment_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_qrpag_alignment (alignment_id), PRIMARY KEY (revision_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_TOPIC FOREIGN KEY (topic_id) REFERENCES curriculum_topics (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_UNIT FOREIGN KEY (unit_id) REFERENCES curriculum_units (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_TOPIC_UNIT FOREIGN KEY (topic_id, unit_id) REFERENCES curriculum_topics (id, unit_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_UNIT_PROGRAM FOREIGN KEY (unit_id, curriculum_program_id) REFERENCES curriculum_units (id, curriculum_program_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE questions ADD CONSTRAINT FK_QUESTION_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE questions ADD CONSTRAINT FK_QUESTION_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE questions ADD CONSTRAINT FK_QUESTION_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE question_revisions ADD CONSTRAINT FK_QR_QUESTION FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revisions ADD CONSTRAINT FK_QR_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE question_revision_options ADD CONSTRAINT FK_QRO_REVISION FOREIGN KEY (revision_id) REFERENCES question_revisions (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE question_answer_keys ADD CONSTRAINT FK_QAK_REVISION FOREIGN KEY (revision_id) REFERENCES question_revisions (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE question_revision_alignments ADD CONSTRAINT FK_QRA_REVISION FOREIGN KEY (revision_id) REFERENCES question_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revision_alignments ADD CONSTRAINT FK_QRA_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE question_revision_alignments ADD CONSTRAINT FK_QRA_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE question_revision_alignments ADD CONSTRAINT FK_QRA_TOPIC FOREIGN KEY (curriculum_topic_id) REFERENCES curriculum_topics (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE question_revision_alignments ADD CONSTRAINT FK_QRA_OUTCOME FOREIGN KEY (learning_outcome_id) REFERENCES curriculum_learning_outcomes (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE question_revision_alignments ADD CONSTRAINT FK_QRA_PROGRAM_SUBJECT FOREIGN KEY (curriculum_program_id, subject_id) REFERENCES curriculum_programs (id, subject_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE question_revision_alignments ADD CONSTRAINT FK_QRA_OUTCOME_TOPIC_PROGRAM FOREIGN KEY (learning_outcome_id, curriculum_topic_id, curriculum_program_id) REFERENCES curriculum_learning_outcomes (id, topic_id, curriculum_program_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT FK_QRPAG_REVISION FOREIGN KEY (revision_id) REFERENCES question_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT FK_QRPAG_ALIGNMENT FOREIGN KEY (alignment_id) REFERENCES question_revision_alignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT FK_QRPAG_ALIGNMENT_REVISION FOREIGN KEY (alignment_id, revision_id) REFERENCES question_revision_alignments (id, revision_id) ON DELETE CASCADE');

        // Doctrine expects hashed IDX_* names for supporting indexes created by composite FKs.
        $this->addSql('ALTER TABLE curriculum_learning_outcomes RENAME INDEX FK_CLO_TOPIC_UNIT TO IDX_F6E8959C1F55203DF8BD700D');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes RENAME INDEX FK_CLO_UNIT_PROGRAM TO IDX_F6E8959CF8BD700DCBC68800');
        $this->addSql('ALTER TABLE question_revision_alignments RENAME INDEX FK_QRA_SUBJECT TO IDX_3FD00C1C23EDC87');
        $this->addSql('ALTER TABLE question_revision_alignments RENAME INDEX FK_QRA_TOPIC TO IDX_3FD00C1CE58DFE79');
        $this->addSql('ALTER TABLE question_revision_alignments RENAME INDEX FK_QRA_PROGRAM_SUBJECT TO IDX_3FD00C1CCBC6880023EDC87');
        $this->addSql('ALTER TABLE question_revision_alignments RENAME INDEX FK_QRA_OUTCOME_TOPIC_PROGRAM TO IDX_3FD00C1C35C2B2D5E58DFE79CBC68800');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards RENAME INDEX FK_QRPAG_ALIGNMENT_REVISION TO IDX_72B8CC73AB7AC2A01DFA7C8F');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP FOREIGN KEY FK_QRPAG_REVISION');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP FOREIGN KEY FK_QRPAG_ALIGNMENT');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP FOREIGN KEY FK_QRPAG_ALIGNMENT_REVISION');
        $this->addSql('ALTER TABLE question_revision_alignments DROP FOREIGN KEY FK_QRA_REVISION');
        $this->addSql('ALTER TABLE question_revision_alignments DROP FOREIGN KEY FK_QRA_PROGRAM');
        $this->addSql('ALTER TABLE question_revision_alignments DROP FOREIGN KEY FK_QRA_SUBJECT');
        $this->addSql('ALTER TABLE question_revision_alignments DROP FOREIGN KEY FK_QRA_TOPIC');
        $this->addSql('ALTER TABLE question_revision_alignments DROP FOREIGN KEY FK_QRA_OUTCOME');
        $this->addSql('ALTER TABLE question_revision_alignments DROP FOREIGN KEY FK_QRA_PROGRAM_SUBJECT');
        $this->addSql('ALTER TABLE question_revision_alignments DROP FOREIGN KEY FK_QRA_OUTCOME_TOPIC_PROGRAM');
        $this->addSql('ALTER TABLE question_answer_keys DROP FOREIGN KEY FK_QAK_REVISION');
        $this->addSql('ALTER TABLE question_revision_options DROP FOREIGN KEY FK_QRO_REVISION');
        $this->addSql('ALTER TABLE question_revisions DROP FOREIGN KEY FK_QR_QUESTION');
        $this->addSql('ALTER TABLE question_revisions DROP FOREIGN KEY FK_QR_CREATED_BY');
        $this->addSql('ALTER TABLE questions DROP FOREIGN KEY FK_QUESTION_INSTITUTION');
        $this->addSql('ALTER TABLE questions DROP FOREIGN KEY FK_QUESTION_SUBJECT');
        $this->addSql('ALTER TABLE questions DROP FOREIGN KEY FK_QUESTION_CREATED_BY');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_TOPIC');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_UNIT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_TOPIC_UNIT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_UNIT_PROGRAM');
        $this->addSql('DROP TABLE question_revision_primary_alignment_guards');
        $this->addSql('DROP TABLE question_revision_alignments');
        $this->addSql('DROP TABLE question_answer_keys');
        $this->addSql('DROP TABLE question_revision_options');
        $this->addSql('DROP TABLE question_revisions');
        $this->addSql('DROP TABLE questions');
        $this->addSql('DROP TABLE curriculum_learning_outcomes');
    }
}
