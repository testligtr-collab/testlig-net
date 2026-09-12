<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.15: learning content + stored media foundation.
 *
 * Irreversible — down() refuses restore.
 * Does not modify Version20260911200000 or earlier migrations.
 */
final class Version20260912120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create learning content and stored media tables with CHECKs, composite FKs, and sealed triggers.';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'learning_contents',
            'learning_content_revisions',
            'learning_content_publications',
            'learning_content_outcome_alignments',
            'learning_content_revision_primary_alignment_guards',
            'stored_media_assets',
            'learning_content_revision_assets',
        ] as $tableName) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($tableName),
            );
            $this->abortIf(
                $exists > 0,
                \sprintf('Version20260912120000 preflight failed: table %s already exists.', $tableName),
            );
        }

        foreach (['subjects', 'institutions', 'users', 'curriculum_learning_outcomes', 'curriculum_programs', 'curriculum_topics'] as $required) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($required),
            );
            $this->abortIf(
                0 === $exists,
                \sprintf('Version20260912120000 requires table %s.', $required),
            );
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_content_revisions (
                id BINARY(16) NOT NULL,
                content_id BINARY(16) NOT NULL,
                revision_number INT NOT NULL,
                schema_version INT NOT NULL,
                structured_content JSON NOT NULL,
                estimated_minutes INT DEFAULT NULL,
                language VARCHAR(16) NOT NULL,
                source_type VARCHAR(32) NOT NULL,
                source_reference VARCHAR(255) DEFAULT NULL,
                accessibility_metadata JSON DEFAULT NULL,
                content_hash VARCHAR(64) NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                sealed_at DATETIME DEFAULT NULL,
                is_sealed TINYINT(1) DEFAULT 0 NOT NULL,
                UNIQUE INDEX uniq_lcr_content_revision_number (content_id, revision_number),
                UNIQUE INDEX uniq_lcr_id_content (id, content_id),
                UNIQUE INDEX uniq_lcr_id_content_number (id, content_id, revision_number),
                INDEX idx_lcr_content (content_id),
                INDEX idx_lcr_created_by (created_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_lcr_revision_number CHECK (revision_number >= 1),
                CONSTRAINT chk_lcr_schema_version CHECK (schema_version >= 1),
                CONSTRAINT chk_lcr_estimated_minutes CHECK (estimated_minutes IS NULL OR estimated_minutes >= 1),
                CONSTRAINT chk_lcr_source_type CHECK (
                    source_type IN ('original', 'imported', 'external_reference', 'cloned')
                ),
                CONSTRAINT chk_lcr_content_hash CHECK (
                    CHAR_LENGTH(content_hash) = 64
                    AND content_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_lcr_sealed_pair CHECK (
                    (is_sealed = 0 AND sealed_at IS NULL)
                    OR (is_sealed = 1 AND sealed_at IS NOT NULL)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_contents (
                id BINARY(16) NOT NULL,
                scope VARCHAR(32) NOT NULL,
                institution_id BINARY(16) DEFAULT NULL,
                subject_id BINARY(16) NOT NULL,
                grade_level INT NOT NULL,
                content_type VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                code VARCHAR(64) NOT NULL,
                slug VARCHAR(200) NOT NULL,
                title VARCHAR(200) NOT NULL,
                normalized_title VARCHAR(200) NOT NULL,
                summary LONGTEXT DEFAULT NULL,
                current_revision_number INT NOT NULL,
                published_revision_id BINARY(16) DEFAULT NULL,
                published_revision_number INT DEFAULT NULL,
                created_by_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                published_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                platform_slug_scope VARCHAR(200) AS (IF(scope = 'platform', slug, NULL)) STORED,
                UNIQUE INDEX uniq_lc_id_institution (id, institution_id),
                UNIQUE INDEX uniq_lc_id_scope (id, scope),
                UNIQUE INDEX uniq_lc_institution_slug (institution_id, slug),
                UNIQUE INDEX uniq_lc_platform_slug (platform_slug_scope),
                INDEX idx_lc_scope_status (scope, status),
                INDEX idx_lc_institution_status (institution_id, status),
                INDEX idx_lc_subject_grade (subject_id, grade_level),
                INDEX idx_lc_created_by (created_by_id),
                INDEX idx_lc_code (code),
                INDEX IDX_LC_PUBLISHED_REVISION (published_revision_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_lc_scope_institution CHECK (
                    ((scope = 'platform' AND institution_id IS NULL)
                     OR (scope = 'institution' AND institution_id IS NOT NULL))
                ),
                CONSTRAINT chk_lc_status CHECK (
                    status IN ('draft', 'in_review', 'published', 'archived')
                ),
                CONSTRAINT chk_lc_content_type CHECK (
                    content_type IN (
                        'topic_explanation', 'video', 'audio', 'document', 'worksheet',
                        'presentation', 'animation', 'simulation', 'educational_game',
                        'interactive', 'external_link'
                    )
                ),
                CONSTRAINT chk_lc_current_revision CHECK (current_revision_number >= 1),
                CONSTRAINT chk_lc_published_revision_pair CHECK (
                    (published_revision_id IS NULL AND published_revision_number IS NULL)
                    OR (published_revision_id IS NOT NULL AND published_revision_number IS NOT NULL AND published_revision_number >= 1)
                ),
                CONSTRAINT chk_lc_published_status CHECK (
                    (status = 'published' AND published_revision_id IS NOT NULL AND published_at IS NOT NULL)
                    OR (status <> 'published')
                ),
                CONSTRAINT chk_lc_archived_at CHECK (
                    (status = 'archived' AND archived_at IS NOT NULL)
                    OR (status <> 'archived' AND archived_at IS NULL)
                ),
                CONSTRAINT chk_lc_code CHECK (code REGEXP '^[a-z][a-z0-9_]{1,63}$')
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_content_publications (
                id BINARY(16) NOT NULL,
                content_id BINARY(16) NOT NULL,
                revision_id BINARY(16) NOT NULL,
                publication_number INT NOT NULL,
                content_hash VARCHAR(64) NOT NULL,
                published_by_id BINARY(16) NOT NULL,
                published_at DATETIME NOT NULL,
                schema_version INT NOT NULL,
                UNIQUE INDEX uniq_lcp_content_publication_number (content_id, publication_number),
                UNIQUE INDEX uniq_lcp_revision (revision_id),
                UNIQUE INDEX uniq_lcp_id_content (id, content_id),
                UNIQUE INDEX uniq_lcp_id_revision (id, revision_id),
                INDEX idx_lcp_content (content_id),
                INDEX idx_lcp_published_by (published_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_lcp_publication_number CHECK (publication_number >= 1),
                CONSTRAINT chk_lcp_schema_version CHECK (schema_version >= 1),
                CONSTRAINT chk_lcp_content_hash CHECK (
                    CHAR_LENGTH(content_hash) = 64
                    AND content_hash REGEXP BINARY '^[0-9a-f]{64}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_content_outcome_alignments (
                id BINARY(16) NOT NULL,
                revision_id BINARY(16) NOT NULL,
                curriculum_program_id BINARY(16) NOT NULL,
                subject_id BINARY(16) NOT NULL,
                curriculum_topic_id BINARY(16) NOT NULL,
                learning_outcome_id BINARY(16) NOT NULL,
                is_primary TINYINT(1) NOT NULL,
                position INT NOT NULL,
                created_at DATETIME NOT NULL,
                primary_revision_scope_id BINARY(16) AS (IF(is_primary, revision_id, NULL)) STORED,
                UNIQUE INDEX uniq_lcoa_revision_outcome (revision_id, learning_outcome_id),
                UNIQUE INDEX uniq_lcoa_id_revision (id, revision_id),
                UNIQUE INDEX uniq_lcoa_id_revision_is_primary (id, revision_id, is_primary),
                UNIQUE INDEX uniq_lcoa_one_primary_per_revision (primary_revision_scope_id),
                INDEX idx_lcoa_revision (revision_id),
                INDEX idx_lcoa_outcome (learning_outcome_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_lcoa_position CHECK (position >= 0),
                CONSTRAINT chk_lcoa_is_primary CHECK (is_primary IN (0, 1))
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_content_revision_primary_alignment_guards (
                revision_id BINARY(16) NOT NULL,
                alignment_id BINARY(16) NOT NULL,
                must_be_primary TINYINT(1) DEFAULT 1 NOT NULL,
                UNIQUE INDEX uniq_lcrpag_alignment (alignment_id),
                PRIMARY KEY (revision_id),
                CONSTRAINT chk_lcrpag_must_be_primary CHECK (must_be_primary = 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE stored_media_assets (
                id BINARY(16) NOT NULL,
                scope VARCHAR(32) NOT NULL,
                institution_id BINARY(16) DEFAULT NULL,
                kind VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                scan_status VARCHAR(32) NOT NULL,
                storage_provider VARCHAR(32) NOT NULL,
                storage_key VARCHAR(512) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(127) NOT NULL,
                byte_size INT NOT NULL,
                content_sha256 VARCHAR(64) NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                ready_at DATETIME DEFAULT NULL,
                quarantined_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_sma_id_institution (id, institution_id),
                UNIQUE INDEX uniq_sma_id_scope (id, scope),
                UNIQUE INDEX uniq_sma_storage_key (storage_key),
                INDEX idx_sma_scope_status (scope, status),
                INDEX idx_sma_institution_status (institution_id, status),
                INDEX idx_sma_kind (kind),
                INDEX idx_sma_created_by (created_by_id),
                INDEX idx_sma_sha256 (content_sha256),
                PRIMARY KEY (id),
                CONSTRAINT chk_sma_scope_institution CHECK (
                    ((scope = 'platform' AND institution_id IS NULL)
                     OR (scope = 'institution' AND institution_id IS NOT NULL))
                ),
                CONSTRAINT chk_sma_kind CHECK (
                    kind IN (
                        'image', 'video', 'audio', 'document', 'presentation',
                        'animation', 'interactive_package', 'subtitle', 'transcript', 'thumbnail'
                    )
                ),
                CONSTRAINT chk_sma_status CHECK (
                    status IN ('pending', 'ready', 'quarantined', 'archived')
                ),
                CONSTRAINT chk_sma_scan_status CHECK (
                    scan_status IN ('pending', 'clean', 'infected', 'failed')
                ),
                CONSTRAINT chk_sma_storage_provider CHECK (
                    storage_provider IN ('local', 's3', 'r2', 'bunny')
                ),
                CONSTRAINT chk_sma_byte_size CHECK (byte_size >= 1),
                CONSTRAINT chk_sma_sha256 CHECK (
                    CHAR_LENGTH(content_sha256) = 64
                    AND content_sha256 REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_sma_ready_requires_clean CHECK (
                    status <> 'ready' OR scan_status = 'clean'
                ),
                CONSTRAINT chk_sma_ready_at CHECK (
                    (status = 'ready' AND ready_at IS NOT NULL)
                    OR (status <> 'ready' AND ready_at IS NULL)
                ),
                CONSTRAINT chk_sma_quarantined_at CHECK (
                    (status = 'quarantined' AND quarantined_at IS NOT NULL)
                    OR (status <> 'quarantined')
                ),
                CONSTRAINT chk_sma_archived_at CHECK (
                    (status = 'archived' AND archived_at IS NOT NULL)
                    OR (status <> 'archived' AND archived_at IS NULL)
                ),
                CONSTRAINT chk_sma_storage_key_safe CHECK (
                    storage_key NOT LIKE '%..%'
                    AND storage_key NOT LIKE '/%'
                    AND storage_key NOT LIKE '%\\%'
                    AND CHAR_LENGTH(storage_key) BETWEEN 1 AND 512
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_content_revision_assets (
                id BINARY(16) NOT NULL,
                revision_id BINARY(16) NOT NULL,
                asset_id BINARY(16) NOT NULL,
                role VARCHAR(32) NOT NULL,
                position INT NOT NULL,
                alt_text VARCHAR(500) DEFAULT NULL,
                caption LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_lcra_revision_asset_role (revision_id, asset_id, role),
                UNIQUE INDEX uniq_lcra_id_revision (id, revision_id),
                INDEX idx_lcra_revision (revision_id),
                INDEX idx_lcra_asset (asset_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_lcra_position CHECK (position >= 0),
                CONSTRAINT chk_lcra_role CHECK (
                    role IN (
                        'cover', 'inline', 'primary_media', 'attachment',
                        'thumbnail', 'subtitle', 'transcript', 'interactive_package'
                    )
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        // FKs — revisions first without content FK, then contents, then wire revisions→contents.
        $this->addSql('ALTER TABLE learning_content_revisions ADD CONSTRAINT FK_LCR_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_contents ADD CONSTRAINT FK_LC_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_contents ADD CONSTRAINT FK_LC_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_contents ADD CONSTRAINT FK_LC_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_contents ADD CONSTRAINT FK_LC_PUBLISHED_REVISION FOREIGN KEY (published_revision_id) REFERENCES learning_content_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_revisions ADD CONSTRAINT FK_LCR_CONTENT FOREIGN KEY (content_id) REFERENCES learning_contents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_contents ADD CONSTRAINT FK_LC_PUBLISHED_REVISION_CONTENT FOREIGN KEY (published_revision_id, id, published_revision_number) REFERENCES learning_content_revisions (id, content_id, revision_number) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE learning_content_publications ADD CONSTRAINT FK_LCP_CONTENT FOREIGN KEY (content_id) REFERENCES learning_contents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_content_publications ADD CONSTRAINT FK_LCP_REVISION FOREIGN KEY (revision_id) REFERENCES learning_content_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_publications ADD CONSTRAINT FK_LCP_PUBLISHED_BY FOREIGN KEY (published_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_publications ADD CONSTRAINT FK_LCP_REVISION_CONTENT FOREIGN KEY (revision_id, content_id) REFERENCES learning_content_revisions (id, content_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE learning_content_outcome_alignments ADD CONSTRAINT FK_LCOA_REVISION FOREIGN KEY (revision_id) REFERENCES learning_content_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments ADD CONSTRAINT FK_LCOA_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments ADD CONSTRAINT FK_LCOA_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments ADD CONSTRAINT FK_LCOA_TOPIC FOREIGN KEY (curriculum_topic_id) REFERENCES curriculum_topics (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments ADD CONSTRAINT FK_LCOA_OUTCOME FOREIGN KEY (learning_outcome_id) REFERENCES curriculum_learning_outcomes (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments ADD CONSTRAINT FK_LCOA_PROGRAM_SUBJECT FOREIGN KEY (curriculum_program_id, subject_id) REFERENCES curriculum_programs (id, subject_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments ADD CONSTRAINT FK_LCOA_OUTCOME_TOPIC_PROGRAM FOREIGN KEY (learning_outcome_id, curriculum_topic_id, curriculum_program_id) REFERENCES curriculum_learning_outcomes (id, topic_id, curriculum_program_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE learning_content_revision_primary_alignment_guards ADD CONSTRAINT FK_LCRPAG_REVISION FOREIGN KEY (revision_id) REFERENCES learning_content_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_content_revision_primary_alignment_guards ADD CONSTRAINT FK_LCRPAG_ALIGNMENT FOREIGN KEY (alignment_id) REFERENCES learning_content_outcome_alignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_content_revision_primary_alignment_guards ADD CONSTRAINT FK_LCRPAG_ALIGNMENT_REVISION_PRIMARY FOREIGN KEY (alignment_id, revision_id, must_be_primary) REFERENCES learning_content_outcome_alignments (id, revision_id, is_primary) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE stored_media_assets ADD CONSTRAINT FK_SMA_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stored_media_assets ADD CONSTRAINT FK_SMA_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE learning_content_revision_assets ADD CONSTRAINT FK_LCRA_REVISION FOREIGN KEY (revision_id) REFERENCES learning_content_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_content_revision_assets ADD CONSTRAINT FK_LCRA_ASSET FOREIGN KEY (asset_id) REFERENCES stored_media_assets (id) ON DELETE RESTRICT');

        // Triggers: sealed revision protection (no session bypass vars).
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcr_bu BEFORE UPDATE ON learning_content_revisions
            FOR EACH ROW
            BEGIN
                IF OLD.is_sealed = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_revisions are immutable when sealed';
                END IF;
                IF NEW.id <> OLD.id
                    OR NEW.content_id <> OLD.content_id
                    OR NEW.revision_number <> OLD.revision_number
                    OR NEW.created_by_id <> OLD.created_by_id
                    OR NEW.created_at <> OLD.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_revisions identity columns are immutable';
                END IF;
                IF OLD.is_sealed = 0 AND NEW.is_sealed = 1 AND NEW.sealed_at IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seal requires sealed_at';
                END IF;
                IF NEW.is_sealed = 0 AND OLD.is_sealed = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_revisions cannot unseal';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcr_bd BEFORE DELETE ON learning_content_revisions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_revisions cannot be deleted';
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcp_bu BEFORE UPDATE ON learning_content_publications
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_publications are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcp_bd BEFORE DELETE ON learning_content_publications
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_publications are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcp_bi BEFORE INSERT ON learning_content_publications
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM learning_content_revisions r
                    WHERE r.id = NEW.revision_id AND r.is_sealed = 0
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot publish unsealed learning content revision';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcoa_bi BEFORE INSERT ON learning_content_outcome_alignments
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM learning_content_revisions r
                    WHERE r.id = NEW.revision_id AND r.is_sealed = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot align sealed learning content revision';
                END IF;
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcoa_bu BEFORE UPDATE ON learning_content_outcome_alignments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_outcome_alignments are immutable';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcoa_bd BEFORE DELETE ON learning_content_outcome_alignments
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM learning_content_revisions r
                    WHERE r.id = OLD.revision_id AND r.is_sealed = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot detach alignment from sealed revision';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcra_bi BEFORE INSERT ON learning_content_revision_assets
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM learning_content_revisions r
                    WHERE r.id = NEW.revision_id AND r.is_sealed = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot attach asset to sealed revision';
                END IF;
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcra_bu BEFORE UPDATE ON learning_content_revision_assets
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning_content_revision_assets are immutable';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcra_bd BEFORE DELETE ON learning_content_revision_assets
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM learning_content_revisions r
                    WHERE r.id = OLD.revision_id AND r.is_sealed = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot detach asset from sealed revision';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260912120000 is irreversible: learning content foundation hardening must not be rolled back.',
        );
    }
}
