<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.16: access package / license / entitlement foundation.
 *
 * Irreversible — down() refuses restore.
 * Does not modify Version20260912150000 or earlier migrations.
 *
 * Adaptation: Assessment catalog grants use grade_level only (Assessment has no subject);
 * LearningContent catalog grants require subject + grade_level.
 */
final class Version20260912160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create access package, license, seat, and resource access policy tables with CHECKs, guards, and triggers.';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'access_packages',
            'access_package_versions',
            'access_package_active_version_guards',
            'access_package_learning_content_grants',
            'access_package_assessment_grants',
            'access_package_catalog_grants',
            'access_licenses',
            'institution_license_seats',
            'institution_license_active_seat_guards',
            'learning_content_access_policies',
            'assessment_access_policies',
        ] as $tableName) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($tableName),
            );
            $this->abortIf(
                $exists > 0,
                \sprintf('Version20260912160000 preflight failed: table %s already exists.', $tableName),
            );
        }

        foreach (['users', 'institutions', 'institution_memberships', 'subjects', 'learning_contents', 'assessments'] as $required) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($required),
            );
            $this->abortIf(0 === $exists, \sprintf('Version20260912160000 requires table %s.', $required));
        }

        $publishedLc = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM learning_contents WHERE status = 'published'",
        );
        $publishedAssessment = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM assessments WHERE status = 'published'",
        );
        $this->write(\sprintf(
            'Preflight published counts: learning_contents=%d assessments=%d (access policies default entitlement_required when first set; no mass backfill).',
            $publishedLc,
            $publishedAssessment,
        ));

        // Inconsistent pointer states only — abort if published without published revision.
        $badLc = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM learning_contents
             WHERE status = 'published'
               AND (published_revision_id IS NULL OR published_revision_number IS NULL)",
        );
        $this->abortIf($badLc > 0, 'Abort: published learning_contents with missing published revision pointer.');

        $badAssessment = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM assessments
             WHERE status = 'published'
               AND (published_revision_id IS NULL OR published_revision_number IS NULL)",
        );
        $this->abortIf($badAssessment > 0, 'Abort: published assessments with missing published revision pointer.');

        $this->addSql(<<<'SQL'
            CREATE TABLE access_packages (
                id BINARY(16) NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(200) NOT NULL,
                normalized_name VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                target_type VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                default_validity_days INT DEFAULT NULL,
                default_seat_limit INT DEFAULT NULL,
                created_by_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                activated_at DATETIME DEFAULT NULL,
                retired_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_ap_code (code),
                INDEX idx_ap_status_target (status, target_type),
                INDEX idx_ap_created_by (created_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_ap_code CHECK (code REGEXP '^[a-z][a-z0-9_]{1,63}$'),
                CONSTRAINT chk_ap_target_type CHECK (target_type IN ('individual', 'institution')),
                CONSTRAINT chk_ap_status CHECK (status IN ('draft', 'active', 'retired')),
                CONSTRAINT chk_ap_validity_days CHECK (default_validity_days IS NULL OR default_validity_days >= 1),
                CONSTRAINT chk_ap_seat_limit CHECK (
                    (target_type = 'individual' AND default_seat_limit IS NULL)
                    OR (target_type = 'institution' AND (default_seat_limit IS NULL OR default_seat_limit >= 1))
                ),
                CONSTRAINT chk_ap_activated_at CHECK (
                    (status IN ('active', 'retired') AND activated_at IS NOT NULL)
                    OR (status = 'draft' AND activated_at IS NULL)
                ),
                CONSTRAINT chk_ap_retired_at CHECK (
                    (status = 'retired' AND retired_at IS NOT NULL)
                    OR (status <> 'retired' AND retired_at IS NULL)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE access_package_versions (
                id BINARY(16) NOT NULL,
                package_id BINARY(16) NOT NULL,
                version_number INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                validity_days INT DEFAULT NULL,
                seat_limit INT DEFAULT NULL,
                policy_hash VARCHAR(64) NOT NULL,
                schema_version INT NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                activated_by_id BINARY(16) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                activated_at DATETIME DEFAULT NULL,
                superseded_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_apv_package_version (package_id, version_number),
                UNIQUE INDEX uniq_apv_id_package (id, package_id),
                INDEX idx_apv_package_status (package_id, status),
                INDEX idx_apv_created_by (created_by_id),
                INDEX IDX_ECE933B4E00EB9A0 (activated_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_apv_version_number CHECK (version_number >= 1),
                CONSTRAINT chk_apv_status CHECK (status IN ('draft', 'active', 'superseded')),
                CONSTRAINT chk_apv_validity_days CHECK (validity_days IS NULL OR validity_days >= 1),
                CONSTRAINT chk_apv_schema_version CHECK (schema_version >= 1),
                CONSTRAINT chk_apv_policy_hash CHECK (
                    CHAR_LENGTH(policy_hash) = 64
                    AND policy_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_apv_active_pair CHECK (
                    (status = 'active' AND activated_at IS NOT NULL AND activated_by_id IS NOT NULL AND superseded_at IS NULL)
                    OR (status = 'superseded' AND activated_at IS NOT NULL AND activated_by_id IS NOT NULL AND superseded_at IS NOT NULL)
                    OR (status = 'draft' AND activated_at IS NULL AND activated_by_id IS NULL AND superseded_at IS NULL)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE access_package_active_version_guards (
                package_id BINARY(16) NOT NULL,
                version_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_apavg_version (version_id),
                PRIMARY KEY (package_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE access_package_learning_content_grants (
                id BINARY(16) NOT NULL,
                version_id BINARY(16) NOT NULL,
                content_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_aplcg_version_content (version_id, content_id),
                UNIQUE INDEX uniq_aplcg_id_version (id, version_id),
                INDEX idx_aplcg_content (content_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE access_package_assessment_grants (
                id BINARY(16) NOT NULL,
                version_id BINARY(16) NOT NULL,
                assessment_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_apag_version_assessment (version_id, assessment_id),
                UNIQUE INDEX uniq_apag_id_version (id, version_id),
                INDEX idx_apag_assessment (assessment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE access_package_catalog_grants (
                id BINARY(16) NOT NULL,
                version_id BINARY(16) NOT NULL,
                resource_kind VARCHAR(32) NOT NULL,
                subject_id BINARY(16) DEFAULT NULL,
                grade_level INT NOT NULL,
                created_at DATETIME NOT NULL,
                catalog_scope_key VARCHAR(128) AS (
                    CASE
                        WHEN resource_kind = 'learning_content' THEN CONCAT('lc:', LOWER(HEX(subject_id)), ':', grade_level)
                        WHEN resource_kind = 'assessment' THEN CONCAT('as:', grade_level)
                        ELSE NULL
                    END
                ) STORED,
                UNIQUE INDEX uniq_apcg_version_scope (version_id, catalog_scope_key),
                UNIQUE INDEX uniq_apcg_id_version (id, version_id),
                INDEX idx_apcg_version_kind (version_id, resource_kind),
                INDEX IDX_C28C106F23EDC87 (subject_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_apcg_kind CHECK (resource_kind IN ('learning_content', 'assessment')),
                CONSTRAINT chk_apcg_subject_pair CHECK (
                    (resource_kind = 'learning_content' AND subject_id IS NOT NULL)
                    OR (resource_kind = 'assessment' AND subject_id IS NULL)
                ),
                CONSTRAINT chk_apcg_grade CHECK (grade_level BETWEEN 1 AND 12)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE access_licenses (
                id BINARY(16) NOT NULL,
                package_id BINARY(16) NOT NULL,
                package_version_id BINARY(16) NOT NULL,
                licensee_type VARCHAR(32) NOT NULL,
                user_id BINARY(16) DEFAULT NULL,
                institution_id BINARY(16) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                source_type VARCHAR(32) NOT NULL,
                external_reference VARCHAR(128) DEFAULT NULL,
                valid_from DATETIME NOT NULL,
                valid_until DATETIME NOT NULL,
                seat_limit INT DEFAULT NULL,
                policy_snapshot_hash VARCHAR(64) NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                activated_by_id BINARY(16) DEFAULT NULL,
                revoked_by_id BINARY(16) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                activated_at DATETIME DEFAULT NULL,
                suspended_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                expired_at DATETIME DEFAULT NULL,
                revocation_reason_code VARCHAR(64) DEFAULT NULL,
                UNIQUE INDEX uniq_al_id_package (id, package_id),
                UNIQUE INDEX uniq_al_id_package_version (id, package_version_id),
                UNIQUE INDEX uniq_al_id_institution (id, institution_id),
                INDEX idx_al_user_status (user_id, status),
                INDEX idx_al_institution_status (institution_id, status),
                INDEX idx_al_package_status (package_id, status),
                INDEX idx_al_valid_range (valid_from, valid_until),
                INDEX IDX_1227CE0E47A0D2F0 (package_version_id),
                INDEX IDX_1227CE0EB03A8386 (created_by_id),
                INDEX IDX_1227CE0EE00EB9A0 (activated_by_id),
                INDEX IDX_1227CE0EFB8FE773 (revoked_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_al_licensee_type CHECK (licensee_type IN ('user', 'institution')),
                CONSTRAINT chk_al_status CHECK (status IN ('pending', 'active', 'suspended', 'expired', 'revoked')),
                CONSTRAINT chk_al_source_type CHECK (source_type IN ('manual', 'purchase', 'promotion', 'institution_contract', 'migration')),
                CONSTRAINT chk_al_null_pair CHECK (
                    (licensee_type = 'user' AND user_id IS NOT NULL AND institution_id IS NULL)
                    OR (licensee_type = 'institution' AND institution_id IS NOT NULL AND user_id IS NULL)
                ),
                CONSTRAINT chk_al_validity_range CHECK (valid_until > valid_from),
                CONSTRAINT chk_al_seat_limit CHECK (
                    (licensee_type = 'user' AND seat_limit IS NULL)
                    OR (licensee_type = 'institution' AND (seat_limit IS NULL OR seat_limit >= 1))
                ),
                CONSTRAINT chk_al_policy_hash CHECK (
                    CHAR_LENGTH(policy_snapshot_hash) = 64
                    AND policy_snapshot_hash REGEXP BINARY '^[0-9a-f]{64}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE institution_license_seats (
                id BINARY(16) NOT NULL,
                license_id BINARY(16) NOT NULL,
                institution_id BINARY(16) NOT NULL,
                membership_id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                status VARCHAR(32) NOT NULL,
                assigned_at DATETIME NOT NULL,
                assigned_by_id BINARY(16) NOT NULL,
                revoked_at DATETIME DEFAULT NULL,
                revoked_by_id BINARY(16) DEFAULT NULL,
                revocation_reason_code VARCHAR(64) DEFAULT NULL,
                UNIQUE INDEX uniq_ils_id_license (id, license_id),
                UNIQUE INDEX uniq_ils_id_membership (id, membership_id),
                UNIQUE INDEX uniq_ils_id_license_membership (id, license_id, membership_id),
                UNIQUE INDEX uniq_ils_id_institution (id, institution_id),
                INDEX idx_ils_license_status (license_id, status),
                INDEX idx_ils_membership (membership_id),
                INDEX idx_ils_user (user_id),
                INDEX IDX_856BE90610405986 (institution_id),
                INDEX IDX_856BE9066E6F1246 (assigned_by_id),
                INDEX IDX_856BE906FB8FE773 (revoked_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_ils_status CHECK (status IN ('active', 'revoked')),
                CONSTRAINT chk_ils_revoked_pair CHECK (
                    (status = 'active' AND revoked_at IS NULL AND revoked_by_id IS NULL AND revocation_reason_code IS NULL)
                    OR (status = 'revoked' AND revoked_at IS NOT NULL AND revoked_by_id IS NOT NULL AND revocation_reason_code IS NOT NULL)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE institution_license_active_seat_guards (
                license_id BINARY(16) NOT NULL,
                membership_id BINARY(16) NOT NULL,
                seat_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_ilasg_seat (seat_id),
                PRIMARY KEY (license_id, membership_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE learning_content_access_policies (
                content_id BINARY(16) NOT NULL,
                access_class VARCHAR(32) NOT NULL,
                set_by_id BINARY(16) NOT NULL,
                set_at DATETIME NOT NULL,
                schema_version INT NOT NULL,
                INDEX IDX_1D3417EA3E16DC62 (set_by_id),
                PRIMARY KEY (content_id),
                CONSTRAINT chk_lcap_access_class CHECK (access_class IN ('free', 'entitlement_required')),
                CONSTRAINT chk_lcap_schema_version CHECK (schema_version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_access_policies (
                assessment_id BINARY(16) NOT NULL,
                access_class VARCHAR(32) NOT NULL,
                set_by_id BINARY(16) NOT NULL,
                set_at DATETIME NOT NULL,
                schema_version INT NOT NULL,
                INDEX IDX_BE02A92C3E16DC62 (set_by_id),
                PRIMARY KEY (assessment_id),
                CONSTRAINT chk_aap_access_class CHECK (access_class IN ('free', 'entitlement_required')),
                CONSTRAINT chk_aap_schema_version CHECK (schema_version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        // Foreign keys
        $this->addSql('ALTER TABLE access_packages ADD CONSTRAINT FK_AP_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_package_versions ADD CONSTRAINT FK_APV_PACKAGE FOREIGN KEY (package_id) REFERENCES access_packages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_package_versions ADD CONSTRAINT FK_APV_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_package_versions ADD CONSTRAINT FK_APV_ACTIVATED_BY FOREIGN KEY (activated_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_package_active_version_guards ADD CONSTRAINT FK_APAVG_PACKAGE FOREIGN KEY (package_id) REFERENCES access_packages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_package_active_version_guards ADD CONSTRAINT FK_APAVG_VERSION FOREIGN KEY (version_id) REFERENCES access_package_versions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_package_active_version_guards ADD CONSTRAINT FK_APAVG_VERSION_PACKAGE FOREIGN KEY (version_id, package_id) REFERENCES access_package_versions (id, package_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_package_learning_content_grants ADD CONSTRAINT FK_APLCG_VERSION FOREIGN KEY (version_id) REFERENCES access_package_versions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_package_learning_content_grants ADD CONSTRAINT FK_APLCG_CONTENT FOREIGN KEY (content_id) REFERENCES learning_contents (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_package_assessment_grants ADD CONSTRAINT FK_APAG_VERSION FOREIGN KEY (version_id) REFERENCES access_package_versions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_package_assessment_grants ADD CONSTRAINT FK_APAG_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_package_catalog_grants ADD CONSTRAINT FK_APCG_VERSION FOREIGN KEY (version_id) REFERENCES access_package_versions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_package_catalog_grants ADD CONSTRAINT FK_APCG_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_PACKAGE FOREIGN KEY (package_id) REFERENCES access_packages (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_PACKAGE_VERSION FOREIGN KEY (package_version_id) REFERENCES access_package_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_PACKAGE_VERSION_PACKAGE FOREIGN KEY (package_version_id, package_id) REFERENCES access_package_versions (id, package_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_ACTIVATED_BY FOREIGN KEY (activated_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE access_licenses ADD CONSTRAINT FK_AL_REVOKED_BY FOREIGN KEY (revoked_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_LICENSE FOREIGN KEY (license_id) REFERENCES access_licenses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_MEMBERSHIP FOREIGN KEY (membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_LICENSE_INSTITUTION FOREIGN KEY (license_id, institution_id) REFERENCES access_licenses (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_MEMBERSHIP_INSTITUTION_USER FOREIGN KEY (membership_id, institution_id, user_id) REFERENCES institution_memberships (id, institution_id, user_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_ASSIGNED_BY FOREIGN KEY (assigned_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE institution_license_seats ADD CONSTRAINT FK_ILS_REVOKED_BY FOREIGN KEY (revoked_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE institution_license_active_seat_guards ADD CONSTRAINT FK_ILASG_LICENSE FOREIGN KEY (license_id) REFERENCES access_licenses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_active_seat_guards ADD CONSTRAINT FK_ILASG_MEMBERSHIP FOREIGN KEY (membership_id) REFERENCES institution_memberships (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_active_seat_guards ADD CONSTRAINT FK_ILASG_SEAT FOREIGN KEY (seat_id) REFERENCES institution_license_seats (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_license_active_seat_guards ADD CONSTRAINT FK_ILASG_SEAT_LICENSE_MEMBERSHIP FOREIGN KEY (seat_id, license_id, membership_id) REFERENCES institution_license_seats (id, license_id, membership_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_content_access_policies ADD CONSTRAINT FK_LCAP_CONTENT FOREIGN KEY (content_id) REFERENCES learning_contents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_content_access_policies ADD CONSTRAINT FK_LCAP_SET_BY FOREIGN KEY (set_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_access_policies ADD CONSTRAINT FK_AAP_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_access_policies ADD CONSTRAINT FK_AAP_SET_BY FOREIGN KEY (set_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        // Align Doctrine-generated association index names (schema:update --dump-sql empty).
        $this->addSql('ALTER TABLE access_licenses RENAME INDEX fk_al_package_version_package TO IDX_1227CE0E47A0D2F0F44CABFF');
        $this->addSql('ALTER TABLE access_package_active_version_guards RENAME INDEX fk_apavg_version_package TO IDX_938215DC4BBC2705F44CABFF');
        $this->addSql('ALTER TABLE institution_license_active_seat_guards RENAME INDEX fk_ilasg_membership TO IDX_A695A52F1FB354CD');
        $this->addSql('ALTER TABLE institution_license_active_seat_guards RENAME INDEX fk_ilasg_seat_license_membership TO IDX_A695A52FC1DAFE35460F904B1FB354CD');
        $this->addSql('ALTER TABLE institution_license_seats RENAME INDEX fk_ils_license_institution TO IDX_856BE906460F904B10405986');
        $this->addSql('ALTER TABLE institution_license_seats RENAME INDEX fk_ils_membership_institution_user TO IDX_856BE9061FB354CD10405986A76ED395');

        // Version number sequential on INSERT + seat limit vs package target
        $this->addSql('DROP TRIGGER IF EXISTS trg_apv_bi_version_number');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apv_bi_version_number
            BEFORE INSERT ON access_package_versions
            FOR EACH ROW
            BEGIN
                DECLARE max_num INT;
                DECLARE pkg_target VARCHAR(32);
                SELECT COALESCE(MAX(version_number), 0) INTO max_num
                  FROM access_package_versions
                 WHERE package_id = NEW.package_id;
                IF NEW.version_number <> (max_num + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'version_number must be next sequential value';
                END IF;
                SELECT target_type INTO pkg_target FROM access_packages WHERE id = NEW.package_id;
                IF pkg_target = 'individual' AND NEW.seat_limit IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'individual package version cannot have seat_limit';
                END IF;
                IF pkg_target = 'institution' AND NEW.seat_limit IS NOT NULL AND NEW.seat_limit < 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'institution seat_limit must be null or >= 1';
                END IF;
            END
            SQL);

        // Immutable package code
        $this->addSql('DROP TRIGGER IF EXISTS trg_ap_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_ap_bu_immutable
            BEFORE UPDATE ON access_packages
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id OR OLD.code <> NEW.code OR OLD.target_type <> NEW.target_type OR OLD.created_by_id <> NEW.created_by_id OR OLD.created_at <> NEW.created_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'access_package identity fields are immutable';
                END IF;
            END
            SQL);

        // Immutable version identity + draft-only content mutation
        $this->addSql('DROP TRIGGER IF EXISTS trg_apv_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apv_bu_immutable
            BEFORE UPDATE ON access_package_versions
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id OR OLD.package_id <> NEW.package_id OR OLD.version_number <> NEW.version_number OR OLD.schema_version <> NEW.schema_version OR OLD.created_by_id <> NEW.created_by_id OR OLD.created_at <> NEW.created_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'access_package_version identity fields are immutable';
                END IF;
                IF OLD.status IN ('active', 'superseded') THEN
                    IF OLD.validity_days <> NEW.validity_days
                       OR NOT (OLD.seat_limit <=> NEW.seat_limit)
                       OR OLD.policy_hash <> NEW.policy_hash
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'access_package_version content is immutable when active or superseded';
                    END IF;
                END IF;
            END
            SQL);

        // Active version guard sync
        $this->addSql('DROP TRIGGER IF EXISTS trg_apv_ai_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apv_ai_guard
            AFTER INSERT ON access_package_versions
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'active' THEN
                    INSERT INTO access_package_active_version_guards (package_id, version_id)
                    VALUES (NEW.package_id, NEW.id);
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_apv_au_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apv_au_guard
            AFTER UPDATE ON access_package_versions
            FOR EACH ROW
            BEGIN
                DECLARE guard_exists INT DEFAULT 0;
                IF OLD.status = 'active' AND NEW.status <> 'active' THEN
                    DELETE FROM access_package_active_version_guards WHERE version_id = NEW.id;
                END IF;
                IF NEW.status = 'active' THEN
                    SELECT COUNT(*) INTO guard_exists
                      FROM access_package_active_version_guards g
                     WHERE g.package_id = NEW.package_id;
                    IF guard_exists = 0 THEN
                        INSERT INTO access_package_active_version_guards (package_id, version_id)
                        VALUES (NEW.package_id, NEW.id);
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_apavg_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apavg_bi
            BEFORE INSERT ON access_package_active_version_guards
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                DECLARE v_package BINARY(16);
                SELECT status, package_id INTO v_status, v_package
                  FROM access_package_versions WHERE id = NEW.version_id;
                IF v_status <> 'active' OR v_package <> NEW.package_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active version guard requires active version for package';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_apavg_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apavg_bd
            BEFORE DELETE ON access_package_active_version_guards
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                SELECT status INTO v_status FROM access_package_versions WHERE id = OLD.version_id;
                IF v_status = 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot detach active version guard while version is active';
                END IF;
            END
            SQL);

        // Seat guard sync
        $this->addSql('DROP TRIGGER IF EXISTS trg_ils_ai_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_ils_ai_guard
            AFTER INSERT ON institution_license_seats
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'active' THEN
                    INSERT INTO institution_license_active_seat_guards (license_id, membership_id, seat_id)
                    VALUES (NEW.license_id, NEW.membership_id, NEW.id);
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_ils_au_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_ils_au_guard
            AFTER UPDATE ON institution_license_seats
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'active' AND NEW.status <> 'active' THEN
                    DELETE FROM institution_license_active_seat_guards WHERE seat_id = NEW.id;
                END IF;
                IF OLD.status <> 'active' AND NEW.status = 'active' THEN
                    INSERT INTO institution_license_active_seat_guards (license_id, membership_id, seat_id)
                    VALUES (NEW.license_id, NEW.membership_id, NEW.id);
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_ilasg_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_ilasg_bi
            BEFORE INSERT ON institution_license_active_seat_guards
            FOR EACH ROW
            BEGIN
                DECLARE s_status VARCHAR(32);
                DECLARE s_license BINARY(16);
                DECLARE s_membership BINARY(16);
                SELECT status, license_id, membership_id INTO s_status, s_license, s_membership
                  FROM institution_license_seats WHERE id = NEW.seat_id;
                IF s_status <> 'active' OR s_license <> NEW.license_id OR s_membership <> NEW.membership_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active seat guard requires matching active seat';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_ilasg_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_ilasg_bd
            BEFORE DELETE ON institution_license_active_seat_guards
            FOR EACH ROW
            BEGIN
                DECLARE s_status VARCHAR(32);
                SELECT status INTO s_status FROM institution_license_seats WHERE id = OLD.seat_id;
                IF s_status = 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot detach active seat guard while seat is active';
                END IF;
            END
            SQL);

        // Grant immutability (no UPDATE of identity)
        $this->addSql('DROP TRIGGER IF EXISTS trg_aplcg_bu');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_aplcg_bu
            BEFORE UPDATE ON access_package_learning_content_grants
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning content grants are immutable';
            END
            SQL);
        $this->addSql('DROP TRIGGER IF EXISTS trg_apag_bu');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apag_bu
            BEFORE UPDATE ON access_package_assessment_grants
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment grants are immutable';
            END
            SQL);
        $this->addSql('DROP TRIGGER IF EXISTS trg_apcg_bu');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apcg_bu
            BEFORE UPDATE ON access_package_catalog_grants
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'catalog grants are immutable';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Version20260912160000 is an irreversible security migration.');
    }
}
