<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.11: assessment attempts + items + encrypted answers + active guards.
 *
 * Irreversible security migration — down() does not drop production attempt history.
 *
 * Does not modify Version20260910500000–Version20260910600000.
 */
final class Version20260910700000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create assessment_attempts, active_guards, attempt_items, and attempt_answers with CHECKs, composite FKs, and immutability triggers';
    }

    public function up(Schema $schema): void
    {
        $existingAttempts = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_attempts'",
        );
        $this->abortIf(
            $existingAttempts > 0,
            'Cannot create assessment_attempts: table already exists.',
        );

        $existingGuards = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_attempt_active_guards'",
        );
        $this->abortIf(
            $existingGuards > 0,
            'Cannot create assessment_attempt_active_guards: table already exists.',
        );

        $existingItems = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_attempt_items'",
        );
        $this->abortIf(
            $existingItems > 0,
            'Cannot create assessment_attempt_items: table already exists.',
        );

        $existingAnswers = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_attempt_answers'",
        );
        $this->abortIf(
            $existingAnswers > 0,
            'Cannot create assessment_attempt_answers: table already exists.',
        );

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

        $hasAdrDeliveryIdUnique = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'assessment_delivery_recipients'
              AND index_name = 'uniq_adr_delivery_id'
            SQL);
        if (0 === $hasAdrDeliveryIdUnique) {
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_adr_delivery_id ON assessment_delivery_recipients (delivery_id, id)',
            );
        }

        $hasAiQuestionRevisionUnique = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'assessment_items'
              AND index_name = 'uniq_assessment_item_id_question_revision'
            SQL);
        if (0 === $hasAiQuestionRevisionUnique) {
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_assessment_item_id_question_revision ON assessment_items (id, question_id, question_revision_id)',
            );
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_attempts (
                id BINARY(16) NOT NULL,
                delivery_id BINARY(16) NOT NULL,
                recipient_id BINARY(16) NOT NULL,
                institution_id BINARY(16) NOT NULL,
                student_membership_id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                assessment_id BINARY(16) NOT NULL,
                assessment_publication_id BINARY(16) NOT NULL,
                publication_number INT NOT NULL,
                attempt_number INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                started_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                submitted_at DATETIME DEFAULT NULL,
                expired_at DATETIME DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                cancelled_by_id BINARY(16) DEFAULT NULL,
                cancellation_reason_code VARCHAR(64) DEFAULT NULL,
                last_activity_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_aa_delivery_recipient_number (delivery_id, recipient_id, attempt_number),
                UNIQUE INDEX uniq_aa_id_delivery (id, delivery_id),
                UNIQUE INDEX uniq_aa_id_recipient (id, recipient_id),
                UNIQUE INDEX uniq_aa_id_institution (id, institution_id),
                UNIQUE INDEX uniq_aa_id_assessment (id, assessment_id),
                UNIQUE INDEX uniq_aa_id_publication (id, assessment_publication_id),
                UNIQUE INDEX uniq_aa_id_user (id, user_id),
                UNIQUE INDEX uniq_aa_id_membership (id, student_membership_id),
                UNIQUE INDEX uniq_aa_id_delivery_recipient (id, delivery_id, recipient_id),
                INDEX idx_aa_institution_status (institution_id, status),
                INDEX idx_aa_delivery_status (delivery_id, status),
                INDEX idx_aa_recipient_status (recipient_id, status),
                INDEX idx_aa_user_status (user_id, status),
                PRIMARY KEY (id),
                CONSTRAINT chk_aa_status CHECK (status IN ('in_progress', 'submitted', 'expired', 'cancelled')),
                CONSTRAINT chk_aa_attempt_number CHECK (attempt_number >= 1),
                CONSTRAINT chk_aa_publication_number CHECK (publication_number >= 1),
                CONSTRAINT chk_aa_window CHECK (started_at < expires_at),
                CONSTRAINT chk_aa_lifecycle_fields CHECK (
                    (
                        status = 'in_progress'
                        AND submitted_at IS NULL
                        AND expired_at IS NULL
                        AND cancelled_at IS NULL AND cancelled_by_id IS NULL AND cancellation_reason_code IS NULL
                    )
                    OR (
                        status = 'submitted'
                        AND submitted_at IS NOT NULL
                        AND expired_at IS NULL
                        AND cancelled_at IS NULL AND cancelled_by_id IS NULL AND cancellation_reason_code IS NULL
                    )
                    OR (
                        status = 'expired'
                        AND expired_at IS NOT NULL
                        AND submitted_at IS NULL
                        AND cancelled_at IS NULL AND cancelled_by_id IS NULL AND cancellation_reason_code IS NULL
                    )
                    OR (
                        status = 'cancelled'
                        AND cancelled_at IS NOT NULL AND cancelled_by_id IS NOT NULL AND cancellation_reason_code IS NOT NULL
                        AND submitted_at IS NULL
                        AND expired_at IS NULL
                    )
                ),
                CONSTRAINT chk_aa_cancellation_reason CHECK (
                    cancellation_reason_code IS NULL
                    OR cancellation_reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_attempt_active_guards (
                recipient_id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                delivery_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_aaag_attempt (attempt_id),
                PRIMARY KEY (recipient_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_attempt_items (
                id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                assessment_section_id BINARY(16) NOT NULL,
                assessment_item_id BINARY(16) NOT NULL,
                question_id BINARY(16) NOT NULL,
                question_revision_id BINARY(16) NOT NULL,
                section_position INT NOT NULL,
                item_position INT NOT NULL,
                presentation_position INT NOT NULL,
                option_order_json JSON DEFAULT NULL,
                required TINYINT(1) NOT NULL,
                points NUMERIC(10, 2) NOT NULL,
                penalty_points NUMERIC(10, 2) NOT NULL,
                public_content_hash VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_aai_attempt_presentation (attempt_id, presentation_position),
                UNIQUE INDEX uniq_aai_attempt_assessment_item (attempt_id, assessment_item_id),
                UNIQUE INDEX uniq_aai_id_attempt (id, attempt_id),
                INDEX idx_aai_attempt (attempt_id),
                INDEX idx_aai_question_revision (question_revision_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_aai_section_position CHECK (section_position >= 1),
                CONSTRAINT chk_aai_item_position CHECK (item_position >= 1),
                CONSTRAINT chk_aai_presentation_position CHECK (presentation_position >= 1),
                CONSTRAINT chk_aai_points CHECK (points > 0),
                CONSTRAINT chk_aai_penalty CHECK (penalty_points >= 0 AND penalty_points <= points),
                CONSTRAINT chk_aai_public_content_hash CHECK (
                    CHAR_LENGTH(public_content_hash) = 64
                    AND public_content_hash REGEXP BINARY '^[0-9a-f]{64}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_attempt_answers (
                id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                attempt_item_id BINARY(16) NOT NULL,
                answer_ciphertext LONGBLOB NOT NULL,
                answer_nonce LONGBLOB NOT NULL,
                encryption_version INT NOT NULL,
                client_revision INT NOT NULL,
                answered_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_aaa_attempt_item (attempt_id, attempt_item_id),
                INDEX idx_aaa_attempt (attempt_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_aaa_encryption_version CHECK (encryption_version >= 1),
                CONSTRAINT chk_aaa_client_revision CHECK (client_revision >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_DELIVERY FOREIGN KEY (delivery_id) REFERENCES assessment_deliveries (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES assessment_delivery_recipients (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_MEMBERSHIP FOREIGN KEY (student_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_PUBLICATION FOREIGN KEY (assessment_publication_id) REFERENCES assessment_publications (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_CANCELLED_BY FOREIGN KEY (cancelled_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_DELIVERY_INSTITUTION FOREIGN KEY (delivery_id, institution_id) REFERENCES assessment_deliveries (id, institution_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_DELIVERY_RECIPIENT FOREIGN KEY (delivery_id, recipient_id) REFERENCES assessment_delivery_recipients (delivery_id, id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_PUBLICATION_ASSESSMENT_NUMBER FOREIGN KEY (assessment_publication_id, assessment_id, publication_number) REFERENCES assessment_publications (id, assessment_id, publication_number) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_MEMBERSHIP_INSTITUTION_USER FOREIGN KEY (student_membership_id, institution_id, user_id) REFERENCES institution_memberships (id, institution_id, user_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_attempt_active_guards ADD CONSTRAINT FK_AAAG_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES assessment_delivery_recipients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_attempt_active_guards ADD CONSTRAINT FK_AAAG_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_attempt_active_guards ADD CONSTRAINT FK_AAAG_DELIVERY FOREIGN KEY (delivery_id) REFERENCES assessment_deliveries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_attempt_active_guards ADD CONSTRAINT FK_AAAG_ATTEMPT_DELIVERY_RECIPIENT FOREIGN KEY (attempt_id, delivery_id, recipient_id) REFERENCES assessment_attempts (id, delivery_id, recipient_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_SECTION FOREIGN KEY (assessment_section_id) REFERENCES assessment_sections (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_ITEM FOREIGN KEY (assessment_item_id) REFERENCES assessment_items (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_QUESTION FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_QUESTION_REVISION FOREIGN KEY (question_revision_id) REFERENCES question_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_ITEM_QUESTION_REVISION FOREIGN KEY (assessment_item_id, question_id, question_revision_id) REFERENCES assessment_items (id, question_id, question_revision_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_attempt_answers ADD CONSTRAINT FK_AAA_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_attempt_answers ADD CONSTRAINT FK_AAA_ITEM FOREIGN KEY (attempt_item_id) REFERENCES assessment_attempt_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_attempt_answers ADD CONSTRAINT FK_AAA_ITEM_ATTEMPT FOREIGN KEY (attempt_item_id, attempt_id) REFERENCES assessment_attempt_items (id, attempt_id) ON DELETE CASCADE');

        // Align MariaDB auto-named FK indexes with Doctrine expected IDX_* names.
        $this->addSql('ALTER TABLE assessment_attempts RENAME INDEX FK_AA_ASSESSMENT TO IDX_C3B1F642DD3DD5F1');
        $this->addSql('ALTER TABLE assessment_attempts RENAME INDEX FK_AA_CANCELLED_BY TO IDX_C3B1F642187B2D12');
        $this->addSql('ALTER TABLE assessment_attempts RENAME INDEX FK_AA_DELIVERY_INSTITUTION TO IDX_C3B1F6421213692110405986');
        $this->addSql('ALTER TABLE assessment_attempts RENAME INDEX FK_AA_PUBLICATION_ASSESSMENT_NUMBER TO IDX_C3B1F642A77821CADD3DD5F1727B0E19');
        $this->addSql('ALTER TABLE assessment_attempts RENAME INDEX FK_AA_MEMBERSHIP_INSTITUTION_USER TO IDX_C3B1F6424A2FAC5310405986A76ED395');
        $this->addSql('ALTER TABLE assessment_attempt_active_guards RENAME INDEX FK_AAAG_DELIVERY TO IDX_8A37189412136921');
        $this->addSql('ALTER TABLE assessment_attempt_active_guards RENAME INDEX FK_AAAG_ATTEMPT_DELIVERY_RECIPIENT TO IDX_8A371894B191BE6B12136921E92F8F78');
        $this->addSql('ALTER TABLE assessment_attempt_answers RENAME INDEX FK_AAA_ITEM_ATTEMPT TO IDX_A7094F07D62A0C8CB191BE6B');
        $this->addSql('ALTER TABLE assessment_attempt_items RENAME INDEX FK_AAI_SECTION TO IDX_34057EF8714D3CDA');
        $this->addSql('ALTER TABLE assessment_attempt_items RENAME INDEX FK_AAI_QUESTION TO IDX_34057EF81E27F6BF');
        $this->addSql('ALTER TABLE assessment_attempt_items RENAME INDEX FK_AAI_ITEM_QUESTION_REVISION TO IDX_34057EF8B891C3901E27F6BFFEFEA302');

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempts_bu_identity');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempts_bu_identity
            BEFORE UPDATE ON assessment_attempts
            FOR EACH ROW
            BEGIN
                IF OLD.delivery_id <> NEW.delivery_id
                   OR OLD.recipient_id <> NEW.recipient_id
                   OR OLD.institution_id <> NEW.institution_id
                   OR OLD.student_membership_id <> NEW.student_membership_id
                   OR OLD.user_id <> NEW.user_id
                   OR OLD.assessment_id <> NEW.assessment_id
                   OR OLD.assessment_publication_id <> NEW.assessment_publication_id
                   OR OLD.publication_number <> NEW.publication_number
                   OR OLD.attempt_number <> NEW.attempt_number
                   OR OLD.started_at <> NEW.started_at
                   OR OLD.expires_at <> NEW.expires_at
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt identity is immutable';
                END IF;

                IF NOT (
                    (OLD.status = 'in_progress' AND NEW.status IN ('in_progress', 'submitted', 'expired', 'cancelled'))
                    OR (OLD.status = 'submitted' AND NEW.status = 'submitted')
                    OR (OLD.status = 'expired' AND NEW.status = 'expired')
                    OR (OLD.status = 'cancelled' AND NEW.status = 'cancelled')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt status transition is not allowed';
                END IF;

                IF NEW.last_activity_at < OLD.last_activity_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt last_activity_at may only move forward';
                END IF;

                IF OLD.status = NEW.status THEN
                    IF NOT (OLD.submitted_at <=> NEW.submitted_at)
                       OR NOT (OLD.expired_at <=> NEW.expired_at)
                       OR NOT (OLD.cancelled_at <=> NEW.cancelled_at)
                       OR NOT (OLD.cancelled_by_id <=> NEW.cancelled_by_id)
                       OR NOT (OLD.cancellation_reason_code <=> NEW.cancellation_reason_code)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt lifecycle fields immutable when status unchanged';
                    END IF;
                ELSEIF OLD.status = 'in_progress' AND NEW.status = 'submitted' THEN
                    IF NEW.submitted_at IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt submit requires submitted_at';
                    END IF;
                    IF OLD.submitted_at IS NOT NULL
                       OR NEW.expired_at IS NOT NULL OR OLD.expired_at IS NOT NULL
                       OR NEW.cancelled_at IS NOT NULL OR NEW.cancelled_by_id IS NOT NULL
                       OR NEW.cancellation_reason_code IS NOT NULL
                       OR OLD.cancelled_at IS NOT NULL OR OLD.cancelled_by_id IS NOT NULL
                       OR OLD.cancellation_reason_code IS NOT NULL
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt submit forbids expired or cancelled fields';
                    END IF;
                ELSEIF OLD.status = 'in_progress' AND NEW.status = 'expired' THEN
                    IF NEW.expired_at IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt expire requires expired_at';
                    END IF;
                    IF OLD.expired_at IS NOT NULL
                       OR NEW.submitted_at IS NOT NULL OR OLD.submitted_at IS NOT NULL
                       OR NEW.cancelled_at IS NOT NULL OR NEW.cancelled_by_id IS NOT NULL
                       OR NEW.cancellation_reason_code IS NOT NULL
                       OR OLD.cancelled_at IS NOT NULL OR OLD.cancelled_by_id IS NOT NULL
                       OR OLD.cancellation_reason_code IS NOT NULL
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt expire forbids submitted or cancelled fields';
                    END IF;
                ELSEIF OLD.status = 'in_progress' AND NEW.status = 'cancelled' THEN
                    IF NEW.cancelled_at IS NULL OR NEW.cancelled_by_id IS NULL OR NEW.cancellation_reason_code IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt cancel requires cancel fields';
                    END IF;
                    IF OLD.cancelled_at IS NOT NULL OR OLD.cancelled_by_id IS NOT NULL
                       OR OLD.cancellation_reason_code IS NOT NULL
                       OR NEW.submitted_at IS NOT NULL OR OLD.submitted_at IS NOT NULL
                       OR NEW.expired_at IS NOT NULL OR OLD.expired_at IS NOT NULL
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt cancel forbids submitted or expired fields';
                    END IF;
                ELSEIF OLD.status <> NEW.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt status transition is not allowed';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempt_items_bu_identity');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempt_items_bu_identity
            BEFORE UPDATE ON assessment_attempt_items
            FOR EACH ROW
            BEGIN
                IF OLD.attempt_id <> NEW.attempt_id
                   OR OLD.assessment_section_id <> NEW.assessment_section_id
                   OR OLD.assessment_item_id <> NEW.assessment_item_id
                   OR OLD.question_id <> NEW.question_id
                   OR OLD.question_revision_id <> NEW.question_revision_id
                   OR OLD.section_position <> NEW.section_position
                   OR OLD.item_position <> NEW.item_position
                   OR OLD.presentation_position <> NEW.presentation_position
                   OR NOT (OLD.option_order_json <=> NEW.option_order_json)
                   OR OLD.required <> NEW.required
                   OR OLD.points <> NEW.points
                   OR OLD.penalty_points <> NEW.penalty_points
                   OR OLD.public_content_hash <> NEW.public_content_hash
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_items are immutable';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempt_answers_bi_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempt_answers_bi_guard
            BEFORE INSERT ON assessment_attempt_answers
            FOR EACH ROW
            BEGIN
                DECLARE attempt_status VARCHAR(32);

                SELECT a.status INTO attempt_status
                  FROM assessment_attempts a
                 WHERE a.id = NEW.attempt_id
                 LIMIT 1;

                IF attempt_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer attempt not found';
                END IF;

                IF attempt_status <> 'in_progress' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer insert requires in_progress attempt';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempt_answers_bu_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempt_answers_bu_guard
            BEFORE UPDATE ON assessment_attempt_answers
            FOR EACH ROW
            BEGIN
                DECLARE attempt_status VARCHAR(32);

                IF OLD.attempt_id <> NEW.attempt_id
                   OR OLD.attempt_item_id <> NEW.attempt_item_id
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer identity is immutable';
                END IF;

                SELECT a.status INTO attempt_status
                  FROM assessment_attempts a
                 WHERE a.id = NEW.attempt_id
                 LIMIT 1;

                IF attempt_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer attempt not found';
                END IF;

                IF attempt_status <> 'in_progress' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer update requires in_progress attempt';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Stage 2.11 assessment attempt migration is irreversible.',
        );
    }
}
