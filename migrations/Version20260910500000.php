<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.10: assessment delivery + recipient snapshot foundation.
 *
 * Irreversible security migration — down() does not drop production delivery history.
 *
 * Does not modify Version20260910120000–Version20260910400000.
 */
final class Version20260910500000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create assessment_deliveries and assessment_delivery_recipients with CHECKs, FKs, and immutability triggers';
    }

    public function up(Schema $schema): void
    {
        $existingDeliveries = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_deliveries'",
        );
        $this->abortIf(
            $existingDeliveries > 0,
            'Cannot create assessment_deliveries: table already exists.',
        );

        $existingRecipients = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_delivery_recipients'",
        );
        $this->abortIf(
            $existingRecipients > 0,
            'Cannot create assessment_delivery_recipients: table already exists.',
        );

        $orphanPublications = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_publications ap
            LEFT JOIN assessments a ON a.id = ap.assessment_id
            WHERE a.id IS NULL
            SQL);
        $this->abortIf(
            $orphanPublications > 0,
            \sprintf(
                'Cannot create assessment deliveries: %d orphan publication(s).',
                $orphanPublications,
            ),
        );

        // Supporting unique for publication composite FK (id + assessment + number).
        $hasApNumberUnique = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'assessment_publications'
              AND index_name = 'uniq_ap_id_assessment_number'
            SQL);
        if (0 === $hasApNumberUnique) {
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_ap_id_assessment_number ON assessment_publications (id, assessment_id, publication_number)',
            );
        }

        $hasMembershipUserUnique = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'institution_memberships'
              AND index_name = 'uniq_membership_id_institution_user'
            SQL);
        if (0 === $hasMembershipUserUnique) {
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_membership_id_institution_user ON institution_memberships (id, institution_id, user_id)',
            );
        }

        $hasCseClassroomMembership = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'classroom_student_enrollments'
              AND index_name = 'uniq_cse_id_classroom_membership'
            SQL);
        if (0 === $hasCseClassroomMembership) {
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_cse_id_classroom_membership ON classroom_student_enrollments (id, classroom_id, student_membership_id)',
            );
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_deliveries (
                id BINARY(16) NOT NULL,
                institution_id BINARY(16) NOT NULL,
                assessment_id BINARY(16) NOT NULL,
                assessment_publication_id BINARY(16) NOT NULL,
                publication_number INT NOT NULL,
                audience_type VARCHAR(32) NOT NULL,
                classroom_id BINARY(16) DEFAULT NULL,
                student_membership_id BINARY(16) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                opens_at DATETIME NOT NULL,
                closes_at DATETIME NOT NULL,
                max_attempts INT NOT NULL,
                title_override VARCHAR(200) DEFAULT NULL,
                instructions_override LONGTEXT DEFAULT NULL,
                created_by_id BINARY(16) NOT NULL,
                activated_by_id BINARY(16) DEFAULT NULL,
                activated_at DATETIME DEFAULT NULL,
                closed_by_id BINARY(16) DEFAULT NULL,
                closed_at DATETIME DEFAULT NULL,
                cancelled_by_id BINARY(16) DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                cancellation_reason_code VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_ad_id_institution (id, institution_id),
                UNIQUE INDEX uniq_ad_id_assessment (id, assessment_id),
                UNIQUE INDEX uniq_ad_id_publication (id, assessment_publication_id),
                INDEX idx_ad_institution_status (institution_id, status),
                INDEX idx_ad_assessment_status (assessment_id, status),
                INDEX idx_ad_publication (assessment_publication_id),
                INDEX idx_ad_classroom_status (classroom_id, status),
                INDEX idx_ad_opens_closes (opens_at, closes_at),
                PRIMARY KEY (id),
                CONSTRAINT chk_ad_audience_targets CHECK (
                    (audience_type = 'institution' AND classroom_id IS NULL AND student_membership_id IS NULL)
                    OR (audience_type = 'classroom' AND classroom_id IS NOT NULL AND student_membership_id IS NULL)
                    OR (audience_type = 'student' AND classroom_id IS NULL AND student_membership_id IS NOT NULL)
                ),
                CONSTRAINT chk_ad_window CHECK (opens_at < closes_at),
                CONSTRAINT chk_ad_max_attempts CHECK (max_attempts >= 1 AND max_attempts <= 10),
                CONSTRAINT chk_ad_status CHECK (status IN ('draft', 'active', 'closed', 'cancelled')),
                CONSTRAINT chk_ad_audience_type CHECK (audience_type IN ('institution', 'classroom', 'student')),
                CONSTRAINT chk_ad_publication_number CHECK (publication_number >= 1),
                CONSTRAINT chk_ad_activated_pair CHECK (
                    (activated_at IS NULL AND activated_by_id IS NULL)
                    OR (activated_at IS NOT NULL AND activated_by_id IS NOT NULL)
                ),
                CONSTRAINT chk_ad_closed_pair CHECK (
                    (closed_at IS NULL AND closed_by_id IS NULL)
                    OR (closed_at IS NOT NULL AND closed_by_id IS NOT NULL)
                ),
                CONSTRAINT chk_ad_cancelled_pair CHECK (
                    (cancelled_at IS NULL AND cancelled_by_id IS NULL AND cancellation_reason_code IS NULL)
                    OR (cancelled_at IS NOT NULL AND cancelled_by_id IS NOT NULL AND cancellation_reason_code IS NOT NULL)
                ),
                CONSTRAINT chk_ad_cancellation_reason CHECK (
                    cancellation_reason_code IS NULL
                    OR cancellation_reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_delivery_recipients (
                id BINARY(16) NOT NULL,
                delivery_id BINARY(16) NOT NULL,
                institution_id BINARY(16) NOT NULL,
                student_membership_id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                status VARCHAR(32) NOT NULL,
                source_classroom_id BINARY(16) DEFAULT NULL,
                source_enrollment_id BINARY(16) DEFAULT NULL,
                assigned_at DATETIME NOT NULL,
                revoked_at DATETIME DEFAULT NULL,
                revoked_by_id BINARY(16) DEFAULT NULL,
                revocation_reason_code VARCHAR(64) DEFAULT NULL,
                UNIQUE INDEX uniq_adr_delivery_membership (delivery_id, student_membership_id),
                UNIQUE INDEX uniq_adr_delivery_user (delivery_id, user_id),
                UNIQUE INDEX uniq_adr_id_delivery (id, delivery_id),
                INDEX idx_adr_delivery_status (delivery_id, status),
                INDEX idx_adr_membership_status (student_membership_id, status),
                INDEX idx_adr_user_status (user_id, status),
                INDEX idx_adr_institution (institution_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_adr_status CHECK (status IN ('eligible', 'revoked')),
                CONSTRAINT chk_adr_revoked_pair CHECK (
                    (status = 'eligible' AND revoked_at IS NULL AND revoked_by_id IS NULL AND revocation_reason_code IS NULL)
                    OR (status = 'revoked' AND revoked_at IS NOT NULL AND revoked_by_id IS NOT NULL AND revocation_reason_code IS NOT NULL)
                ),
                CONSTRAINT chk_adr_revocation_reason CHECK (
                    revocation_reason_code IS NULL
                    OR revocation_reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                ),
                CONSTRAINT chk_adr_source_enrollment_classroom CHECK (
                    (source_enrollment_id IS NULL AND source_classroom_id IS NULL)
                    OR (source_enrollment_id IS NOT NULL AND source_classroom_id IS NOT NULL)
                    OR (source_enrollment_id IS NULL AND source_classroom_id IS NOT NULL)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_PUBLICATION FOREIGN KEY (assessment_publication_id) REFERENCES assessment_publications (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_CLASSROOM FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_STUDENT_MEMBERSHIP FOREIGN KEY (student_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_ACTIVATED_BY FOREIGN KEY (activated_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_CLOSED_BY FOREIGN KEY (closed_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_CANCELLED_BY FOREIGN KEY (cancelled_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_PUBLICATION_ASSESSMENT_NUMBER FOREIGN KEY (assessment_publication_id, assessment_id, publication_number) REFERENCES assessment_publications (id, assessment_id, publication_number) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_CLASSROOM_INSTITUTION FOREIGN KEY (classroom_id, institution_id) REFERENCES classrooms (id, institution_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_deliveries ADD CONSTRAINT FK_AD_MEMBERSHIP_INSTITUTION FOREIGN KEY (student_membership_id, institution_id) REFERENCES institution_memberships (id, institution_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_DELIVERY FOREIGN KEY (delivery_id) REFERENCES assessment_deliveries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_MEMBERSHIP FOREIGN KEY (student_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_SOURCE_CLASSROOM FOREIGN KEY (source_classroom_id) REFERENCES classrooms (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_SOURCE_ENROLLMENT FOREIGN KEY (source_enrollment_id) REFERENCES classroom_student_enrollments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_REVOKED_BY FOREIGN KEY (revoked_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_DELIVERY_INSTITUTION FOREIGN KEY (delivery_id, institution_id) REFERENCES assessment_deliveries (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_MEMBERSHIP_INSTITUTION_USER FOREIGN KEY (student_membership_id, institution_id, user_id) REFERENCES institution_memberships (id, institution_id, user_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_SOURCE_CLASSROOM_INSTITUTION FOREIGN KEY (source_classroom_id, institution_id) REFERENCES classrooms (id, institution_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_delivery_recipients ADD CONSTRAINT FK_ADR_SOURCE_ENROLLMENT_CLASSROOM_MEMBERSHIP FOREIGN KEY (source_enrollment_id, source_classroom_id, student_membership_id) REFERENCES classroom_student_enrollments (id, classroom_id, student_membership_id) ON DELETE RESTRICT');

        // Doctrine expects hashed IDX_* names for supporting indexes created by composite FKs.
        $this->addSql('ALTER TABLE assessment_deliveries RENAME INDEX FK_AD_CREATED_BY TO IDX_C6695AC8B03A8386');
        $this->addSql('ALTER TABLE assessment_deliveries RENAME INDEX FK_AD_ACTIVATED_BY TO IDX_C6695AC8E00EB9A0');
        $this->addSql('ALTER TABLE assessment_deliveries RENAME INDEX FK_AD_CLOSED_BY TO IDX_C6695AC8E1FA7797');
        $this->addSql('ALTER TABLE assessment_deliveries RENAME INDEX FK_AD_CANCELLED_BY TO IDX_C6695AC8187B2D12');
        $this->addSql('ALTER TABLE assessment_deliveries RENAME INDEX FK_AD_PUBLICATION_ASSESSMENT_NUMBER TO IDX_C6695AC8A77821CADD3DD5F1727B0E19');
        $this->addSql('ALTER TABLE assessment_deliveries RENAME INDEX FK_AD_CLASSROOM_INSTITUTION TO IDX_C6695AC86278D5A810405986');
        $this->addSql('ALTER TABLE assessment_deliveries RENAME INDEX FK_AD_MEMBERSHIP_INSTITUTION TO IDX_C6695AC84A2FAC5310405986');
        $this->addSql('ALTER TABLE assessment_delivery_recipients RENAME INDEX FK_ADR_REVOKED_BY TO IDX_D4203422FB8FE773');
        $this->addSql('ALTER TABLE assessment_delivery_recipients RENAME INDEX FK_ADR_DELIVERY_INSTITUTION TO IDX_D42034221213692110405986');
        $this->addSql('ALTER TABLE assessment_delivery_recipients RENAME INDEX FK_ADR_MEMBERSHIP_INSTITUTION_USER TO IDX_D42034224A2FAC5310405986A76ED395');
        $this->addSql('ALTER TABLE assessment_delivery_recipients RENAME INDEX FK_ADR_SOURCE_CLASSROOM_INSTITUTION TO IDX_D4203422B492091010405986');
        $this->addSql('ALTER TABLE assessment_delivery_recipients RENAME INDEX FK_ADR_SOURCE_ENROLLMENT_CLASSROOM_MEMBERSHIP TO IDX_D42034224A116339B49209104A2FAC53');

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_deliveries_bu_identity');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_deliveries_bu_identity
            BEFORE UPDATE ON assessment_deliveries
            FOR EACH ROW
            BEGIN
                IF OLD.assessment_publication_id <> NEW.assessment_publication_id
                   OR OLD.assessment_id <> NEW.assessment_id
                   OR OLD.publication_number <> NEW.publication_number
                   OR OLD.institution_id <> NEW.institution_id
                   OR OLD.audience_type <> NEW.audience_type
                   OR NOT (
                        (OLD.classroom_id IS NULL AND NEW.classroom_id IS NULL)
                        OR (OLD.classroom_id <=> NEW.classroom_id)
                   )
                   OR NOT (
                        (OLD.student_membership_id IS NULL AND NEW.student_membership_id IS NULL)
                        OR (OLD.student_membership_id <=> NEW.student_membership_id)
                   )
                   OR OLD.created_by_id <> NEW.created_by_id
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery identity is immutable';
                END IF;

                IF OLD.status <> 'draft' THEN
                    IF OLD.opens_at <> NEW.opens_at
                       OR OLD.closes_at <> NEW.closes_at
                       OR OLD.max_attempts <> NEW.max_attempts
                       OR NOT (OLD.title_override <=> NEW.title_override)
                       OR NOT (OLD.instructions_override <=> NEW.instructions_override)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery content is immutable after draft';
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_delivery_recipients_bu_identity');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_delivery_recipients_bu_identity
            BEFORE UPDATE ON assessment_delivery_recipients
            FOR EACH ROW
            BEGIN
                IF OLD.delivery_id <> NEW.delivery_id
                   OR OLD.institution_id <> NEW.institution_id
                   OR OLD.student_membership_id <> NEW.student_membership_id
                   OR OLD.user_id <> NEW.user_id
                   OR NOT (OLD.source_classroom_id <=> NEW.source_classroom_id)
                   OR NOT (OLD.source_enrollment_id <=> NEW.source_enrollment_id)
                   OR OLD.assigned_at <> NEW.assigned_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery_recipient identity is immutable';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Stage 2.10 assessment delivery migration is irreversible.',
        );
    }
}
