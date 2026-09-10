<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.9: immutable assessment blueprint (revision/section/item/publication) with sealing triggers.
 *
 * Does not modify Version20260909200000 or earlier migrations.
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create assessment blueprint tables with sealed-revision and append-only guarantees';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE assessments (
                id BINARY(16) NOT NULL,
                scope VARCHAR(32) NOT NULL,
                institution_id BINARY(16) DEFAULT NULL,
                type VARCHAR(32) NOT NULL,
                grade_level INT NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                status VARCHAR(32) NOT NULL,
                current_revision_number INT NOT NULL,
                published_revision_number INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_assessment_id_institution (id, institution_id),
                UNIQUE INDEX uniq_assessment_id_scope (id, scope),
                INDEX idx_assessment_scope_status (scope, status),
                INDEX idx_assessment_institution_status (institution_id, status),
                INDEX idx_assessment_grade (grade_level),
                INDEX idx_assessment_created_by (created_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_assessment_scope_institution CHECK (
                    ((scope = 'platform' AND institution_id IS NULL)
                     OR (scope = 'institution' AND institution_id IS NOT NULL))
                ),
                CONSTRAINT chk_assessment_current_revision CHECK (current_revision_number >= 1),
                CONSTRAINT chk_assessment_published_revision CHECK (
                    published_revision_number IS NULL OR published_revision_number >= 1
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_revisions (
                id BINARY(16) NOT NULL,
                assessment_id BINARY(16) NOT NULL,
                revision_number INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                instructions LONGTEXT DEFAULT NULL,
                duration_seconds INT DEFAULT NULL,
                navigation_mode VARCHAR(32) NOT NULL,
                question_order_mode VARCHAR(32) NOT NULL,
                option_order_mode VARCHAR(32) NOT NULL,
                result_release_policy VARCHAR(32) NOT NULL,
                pass_score_percentage NUMERIC(5, 2) DEFAULT NULL,
                created_by_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                public_content_hash VARCHAR(64) NOT NULL,
                schema_version INT NOT NULL,
                is_sealed TINYINT(1) DEFAULT 0 NOT NULL,
                UNIQUE INDEX uniq_assessment_revision_number (assessment_id, revision_number),
                UNIQUE INDEX uniq_assessment_revision_id_assessment (id, assessment_id),
                INDEX idx_assessment_revision_assessment (assessment_id),
                INDEX idx_assessment_revision_created_by (created_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_assessment_revision_number CHECK (revision_number >= 1),
                CONSTRAINT chk_assessment_revision_schema CHECK (schema_version >= 1),
                CONSTRAINT chk_assessment_revision_duration CHECK (
                    duration_seconds IS NULL OR (duration_seconds >= 60 AND duration_seconds <= 21600)
                ),
                CONSTRAINT chk_assessment_revision_pass_score CHECK (
                    pass_score_percentage IS NULL
                    OR (pass_score_percentage >= 0 AND pass_score_percentage <= 100)
                ),
                CONSTRAINT chk_assessment_revision_hash CHECK (
                    CHAR_LENGTH(public_content_hash) = 64
                    AND public_content_hash REGEXP BINARY '^[0-9a-f]{64}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_sections (
                id BINARY(16) NOT NULL,
                revision_id BINARY(16) NOT NULL,
                title VARCHAR(200) NOT NULL,
                instructions LONGTEXT DEFAULT NULL,
                position INT NOT NULL,
                duration_seconds INT DEFAULT NULL,
                question_order_mode VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_assessment_section_revision_position (revision_id, position),
                UNIQUE INDEX uniq_assessment_section_id_revision (id, revision_id),
                INDEX idx_assessment_section_revision (revision_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_assessment_section_position CHECK (position >= 1),
                CONSTRAINT chk_assessment_section_duration CHECK (
                    duration_seconds IS NULL OR (duration_seconds >= 60 AND duration_seconds <= 21600)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_items (
                id BINARY(16) NOT NULL,
                section_id BINARY(16) NOT NULL,
                assessment_revision_id BINARY(16) NOT NULL,
                question_id BINARY(16) NOT NULL,
                question_revision_id BINARY(16) NOT NULL,
                position INT NOT NULL,
                points NUMERIC(10, 2) NOT NULL,
                penalty_points NUMERIC(10, 2) NOT NULL,
                required TINYINT(1) NOT NULL,
                option_order_mode VARCHAR(32) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_assessment_item_section_position (section_id, position),
                UNIQUE INDEX uniq_assessment_item_revision_question_revision (assessment_revision_id, question_revision_id),
                UNIQUE INDEX uniq_assessment_item_id_section (id, section_id),
                UNIQUE INDEX uniq_assessment_item_id_assessment_revision (id, assessment_revision_id),
                INDEX idx_assessment_item_section (section_id),
                INDEX idx_assessment_item_assessment_revision (assessment_revision_id),
                INDEX idx_assessment_item_question (question_id),
                INDEX idx_assessment_item_question_revision (question_revision_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_assessment_item_position CHECK (position >= 1),
                CONSTRAINT chk_assessment_item_points CHECK (points > 0),
                CONSTRAINT chk_assessment_item_penalty CHECK (penalty_points >= 0 AND penalty_points <= points)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_publications (
                id BINARY(16) NOT NULL,
                assessment_id BINARY(16) NOT NULL,
                assessment_revision_id BINARY(16) NOT NULL,
                publication_number INT NOT NULL,
                manifest JSON NOT NULL,
                manifest_hash VARCHAR(64) NOT NULL,
                published_by_id BINARY(16) NOT NULL,
                published_at DATETIME NOT NULL,
                schema_version INT NOT NULL,
                UNIQUE INDEX uniq_assessment_publication_number (assessment_id, publication_number),
                UNIQUE INDEX uniq_assessment_publication_revision (assessment_revision_id),
                UNIQUE INDEX uniq_assessment_publication_id_assessment (id, assessment_id),
                UNIQUE INDEX uniq_assessment_publication_id_revision (id, assessment_revision_id),
                INDEX idx_assessment_publication_assessment (assessment_id),
                INDEX idx_assessment_publication_published_by (published_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_assessment_publication_number CHECK (publication_number >= 1),
                CONSTRAINT chk_assessment_publication_schema CHECK (schema_version >= 1),
                CONSTRAINT chk_assessment_publication_hash CHECK (
                    CHAR_LENGTH(manifest_hash) = 64
                    AND manifest_hash REGEXP BINARY '^[0-9a-f]{64}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE assessments ADD CONSTRAINT FK_ASSESSMENT_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessments ADD CONSTRAINT FK_ASSESSMENT_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_revisions ADD CONSTRAINT FK_AR_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_revisions ADD CONSTRAINT FK_AR_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_sections ADD CONSTRAINT FK_AS_REVISION FOREIGN KEY (revision_id) REFERENCES assessment_revisions (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE assessment_items ADD CONSTRAINT FK_AI_SECTION FOREIGN KEY (section_id) REFERENCES assessment_sections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_items ADD CONSTRAINT FK_AI_ASSESSMENT_REVISION FOREIGN KEY (assessment_revision_id) REFERENCES assessment_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_items ADD CONSTRAINT FK_AI_QUESTION FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_items ADD CONSTRAINT FK_AI_QUESTION_REVISION FOREIGN KEY (question_revision_id) REFERENCES question_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_items ADD CONSTRAINT FK_AI_SECTION_REVISION FOREIGN KEY (section_id, assessment_revision_id) REFERENCES assessment_sections (id, revision_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_items ADD CONSTRAINT FK_AI_QUESTION_REVISION_CHAIN FOREIGN KEY (question_revision_id, question_id) REFERENCES question_revisions (id, question_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_publications ADD CONSTRAINT FK_AP_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_publications ADD CONSTRAINT FK_AP_REVISION FOREIGN KEY (assessment_revision_id) REFERENCES assessment_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_publications ADD CONSTRAINT FK_AP_PUBLISHED_BY FOREIGN KEY (published_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_publications ADD CONSTRAINT FK_AP_REVISION_ASSESSMENT FOREIGN KEY (assessment_revision_id, assessment_id) REFERENCES assessment_revisions (id, assessment_id) ON DELETE RESTRICT');

        // Doctrine expects hashed IDX_* names for supporting indexes created by composite FKs.
        $this->addSql('ALTER TABLE assessment_items RENAME INDEX FK_AI_SECTION_REVISION TO IDX_E83AD65BD823E37ACB85B1B8');
        $this->addSql('ALTER TABLE assessment_items RENAME INDEX FK_AI_QUESTION_REVISION_CHAIN TO IDX_E83AD65BFEFEA3021E27F6BF');
        $this->addSql('ALTER TABLE assessment_publications RENAME INDEX FK_AP_REVISION_ASSESSMENT TO IDX_8A13E036CB85B1B8DD3DD5F1');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_revisions_bu BEFORE UPDATE ON assessment_revisions
            FOR EACH ROW
            BEGIN
                IF NOT (
                    OLD.is_sealed = 0
                    AND NEW.is_sealed = 1
                    AND OLD.id <=> NEW.id
                    AND OLD.assessment_id <=> NEW.assessment_id
                    AND OLD.revision_number <=> NEW.revision_number
                    AND OLD.title <=> NEW.title
                    AND OLD.description <=> NEW.description
                    AND OLD.instructions <=> NEW.instructions
                    AND OLD.duration_seconds <=> NEW.duration_seconds
                    AND OLD.navigation_mode <=> NEW.navigation_mode
                    AND OLD.question_order_mode <=> NEW.question_order_mode
                    AND OLD.option_order_mode <=> NEW.option_order_mode
                    AND OLD.result_release_policy <=> NEW.result_release_policy
                    AND OLD.pass_score_percentage <=> NEW.pass_score_percentage
                    AND OLD.created_by_id <=> NEW.created_by_id
                    AND OLD.created_at <=> NEW.created_at
                    AND OLD.public_content_hash <=> NEW.public_content_hash
                    AND OLD.schema_version <=> NEW.schema_version
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_revisions are append-only except seal';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_revisions_bd BEFORE DELETE ON assessment_revisions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_revisions are append-only';
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_sections_bu BEFORE UPDATE ON assessment_sections
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_sections are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_sections_bd BEFORE DELETE ON assessment_sections
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_sections are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_sections_bi BEFORE INSERT ON assessment_sections
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM assessment_revisions ar
                    WHERE ar.id = NEW.revision_id AND ar.is_sealed = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot insert section into sealed revision';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_items_bu BEFORE UPDATE ON assessment_items
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_items are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_items_bd BEFORE DELETE ON assessment_items
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_items are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_items_bi BEFORE INSERT ON assessment_items
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM assessment_revisions ar
                    WHERE ar.id = NEW.assessment_revision_id AND ar.is_sealed = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot insert item into sealed revision';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_publications_bu BEFORE UPDATE ON assessment_publications
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_publications are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_publications_bd BEFORE DELETE ON assessment_publications
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_publications are append-only';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_publications_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_publications_bu');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_items_bi');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_items_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_items_bu');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_sections_bi');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_sections_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_sections_bu');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_revisions_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_revisions_bu');

        $this->addSql('ALTER TABLE assessment_publications DROP FOREIGN KEY FK_AP_REVISION_ASSESSMENT');
        $this->addSql('ALTER TABLE assessment_publications DROP FOREIGN KEY FK_AP_PUBLISHED_BY');
        $this->addSql('ALTER TABLE assessment_publications DROP FOREIGN KEY FK_AP_REVISION');
        $this->addSql('ALTER TABLE assessment_publications DROP FOREIGN KEY FK_AP_ASSESSMENT');
        $this->addSql('ALTER TABLE assessment_items DROP FOREIGN KEY FK_AI_QUESTION_REVISION_CHAIN');
        $this->addSql('ALTER TABLE assessment_items DROP FOREIGN KEY FK_AI_SECTION_REVISION');
        $this->addSql('ALTER TABLE assessment_items DROP FOREIGN KEY FK_AI_QUESTION_REVISION');
        $this->addSql('ALTER TABLE assessment_items DROP FOREIGN KEY FK_AI_QUESTION');
        $this->addSql('ALTER TABLE assessment_items DROP FOREIGN KEY FK_AI_ASSESSMENT_REVISION');
        $this->addSql('ALTER TABLE assessment_items DROP FOREIGN KEY FK_AI_SECTION');
        $this->addSql('ALTER TABLE assessment_sections DROP FOREIGN KEY FK_AS_REVISION');
        $this->addSql('ALTER TABLE assessment_revisions DROP FOREIGN KEY FK_AR_CREATED_BY');
        $this->addSql('ALTER TABLE assessment_revisions DROP FOREIGN KEY FK_AR_ASSESSMENT');
        $this->addSql('ALTER TABLE assessments DROP FOREIGN KEY FK_ASSESSMENT_CREATED_BY');
        $this->addSql('ALTER TABLE assessments DROP FOREIGN KEY FK_ASSESSMENT_INSTITUTION');

        $this->addSql('DROP TABLE assessment_publications');
        $this->addSql('DROP TABLE assessment_items');
        $this->addSql('DROP TABLE assessment_sections');
        $this->addSql('DROP TABLE assessment_revisions');
        $this->addSql('DROP TABLE assessments');
    }
}
