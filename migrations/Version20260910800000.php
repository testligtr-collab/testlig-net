<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.11 merge-pre: assessment attempt DB integrity hardening.
 *
 * - Single-active in_progress per recipient via STORED generated scope + UNIQUE
 * - Publication/revision graph binding on attempts and attempt items
 * - Active-guard canonical ownership via DB triggers (app must not double-manage)
 * - Attempt INSERT scope/eligibility validation
 * - Answer BI/BU expiry, revision, nonce, and ciphertext hardening
 *
 * max_attempts COUNT in trg_assessment_attempts_bi_scope is not race-safe alone;
 * concurrency relies on uniq_aa_active_recipient_scope + application locks.
 *
 * Irreversible security migration — down() does not undo production hardening.
 *
 * Does not modify Version20260910700000 or earlier.
 */
final class Version20260910800000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden assessment attempt single-active scope, publication graph FKs/triggers, guard sync, and answer format guards';
    }

    public function up(Schema $schema): void
    {
        $attemptTable = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_attempts'",
        );
        $this->abortIf(
            0 === $attemptTable,
            'Version20260910800000 requires assessment_attempts from Version20260910700000.',
        );

        $attemptCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessment_attempts');
        $guardCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessment_attempt_active_guards');
        $itemCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessment_attempt_items');
        $answerCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessment_attempt_answers');

        $inProgressWithoutGuard = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_attempts a
            WHERE a.status = 'in_progress'
              AND NOT EXISTS (
                    SELECT 1 FROM assessment_attempt_active_guards g
                     WHERE g.attempt_id = a.id
                       AND g.recipient_id = a.recipient_id
                       AND g.delivery_id = a.delivery_id
              )
            SQL);
        $this->abortIf(
            $inProgressWithoutGuard > 0,
            \sprintf(
                'Cannot harden attempts: %d in_progress attempt(s) lack matching active_guard (attempts=%d, guards=%d, items=%d, answers=%d). No silent delete.',
                $inProgressWithoutGuard,
                $attemptCount,
                $guardCount,
                $itemCount,
                $answerCount,
            ),
        );

        $guardOnNonInProgress = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_attempt_active_guards g
            INNER JOIN assessment_attempts a ON a.id = g.attempt_id
            WHERE a.status <> 'in_progress'
            SQL);
        $this->abortIf(
            $guardOnNonInProgress > 0,
            \sprintf(
                'Cannot harden attempts: %d active_guard(s) point at non-in_progress attempt(s) (attempts=%d, guards=%d, items=%d, answers=%d). No silent delete.',
                $guardOnNonInProgress,
                $attemptCount,
                $guardCount,
                $itemCount,
                $answerCount,
            ),
        );

        $duplicateInProgress = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT a.recipient_id
                  FROM assessment_attempts a
                 WHERE a.status = 'in_progress'
                 GROUP BY a.recipient_id
                HAVING COUNT(*) > 1
            ) dup
            SQL);
        $this->abortIf(
            $duplicateInProgress > 0,
            \sprintf(
                'Cannot harden attempts: %d recipient(s) have duplicate in_progress attempts (attempts=%d, guards=%d, items=%d, answers=%d). No silent delete.',
                $duplicateInProgress,
                $attemptCount,
                $guardCount,
                $itemCount,
                $answerCount,
            ),
        );

        $badAnswers = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_attempt_answers ans
            WHERE ans.encryption_version < 1
               OR ans.client_revision < 1
               OR OCTET_LENGTH(ans.answer_nonce) <> 24
               OR OCTET_LENGTH(ans.answer_ciphertext) < 16
            SQL);
        $this->abortIf(
            $badAnswers > 0,
            \sprintf(
                'Cannot harden answers: %d answer(s) violate nonce/ciphertext/version format rules (attempts=%d, guards=%d, items=%d, answers=%d). No silent delete.',
                $badAnswers,
                $attemptCount,
                $guardCount,
                $itemCount,
                $answerCount,
            ),
        );

        // A) Single-active guarantee via STORED generated scope + UNIQUE (NULL = terminal).
        $this->addSql(<<<'SQL'
            ALTER TABLE assessment_attempts
                ADD COLUMN active_recipient_scope_id BINARY(16)
                    AS (IF(`status` = 'in_progress', `recipient_id`, NULL)) STORED
            SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_aa_active_recipient_scope ON assessment_attempts (active_recipient_scope_id)',
        );

        // B) Publication graph binding — attempts.assessment_revision_id
        $this->connection->executeStatement(
            'ALTER TABLE assessment_attempts ADD COLUMN assessment_revision_id BINARY(16) NULL',
        );
        $this->connection->executeStatement(<<<'SQL'
            UPDATE assessment_attempts a
            INNER JOIN assessment_publications p ON p.id = a.assessment_publication_id
            SET a.assessment_revision_id = p.assessment_revision_id
            SQL);

        $nullAttemptRevision = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempts WHERE assessment_revision_id IS NULL',
        );
        $this->abortIf(
            $nullAttemptRevision > 0,
            \sprintf(
                'Cannot harden attempts: %d attempt(s) could not backfill assessment_revision_id from publication (attempts=%d). No silent delete.',
                $nullAttemptRevision,
                $attemptCount,
            ),
        );

        $this->addSql(
            'ALTER TABLE assessment_attempts MODIFY assessment_revision_id BINARY(16) NOT NULL',
        );
        $this->addSql(
            'ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_REVISION FOREIGN KEY (assessment_revision_id) REFERENCES assessment_revisions (id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_aa_id_revision ON assessment_attempts (id, assessment_revision_id)',
        );
        $this->addSql(
            'ALTER TABLE assessment_attempts ADD CONSTRAINT FK_AA_PUBLICATION_REVISION FOREIGN KEY (assessment_publication_id, assessment_revision_id) REFERENCES assessment_publications (id, assessment_revision_id) ON DELETE RESTRICT',
        );

        // B) attempt items — denormalized revision + composite graph FKs
        $this->connection->executeStatement(
            'ALTER TABLE assessment_attempt_items ADD COLUMN assessment_revision_id BINARY(16) NULL',
        );
        $this->connection->executeStatement(<<<'SQL'
            UPDATE assessment_attempt_items i
            INNER JOIN assessment_attempts a ON a.id = i.attempt_id
            SET i.assessment_revision_id = a.assessment_revision_id
            SQL);

        $nullItemRevision = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempt_items WHERE assessment_revision_id IS NULL',
        );
        $this->abortIf(
            $nullItemRevision > 0,
            \sprintf(
                'Cannot harden attempt items: %d item(s) could not backfill assessment_revision_id (items=%d). No silent delete.',
                $nullItemRevision,
                $itemCount,
            ),
        );

        $this->addSql(
            'ALTER TABLE assessment_attempt_items MODIFY assessment_revision_id BINARY(16) NOT NULL',
        );
        $this->addSql(
            'ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_REVISION FOREIGN KEY (assessment_revision_id) REFERENCES assessment_revisions (id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_aai_id_attempt_revision ON assessment_attempt_items (id, attempt_id, assessment_revision_id)',
        );
        $this->addSql(
            'ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_ATTEMPT_REVISION FOREIGN KEY (attempt_id, assessment_revision_id) REFERENCES assessment_attempts (id, assessment_revision_id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_SECTION_REVISION FOREIGN KEY (assessment_section_id, assessment_revision_id) REFERENCES assessment_sections (id, revision_id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'ALTER TABLE assessment_attempt_items ADD CONSTRAINT FK_AAI_ITEM_SECTION FOREIGN KEY (assessment_item_id, assessment_section_id) REFERENCES assessment_items (id, section_id) ON DELETE RESTRICT',
        );

        // Align MariaDB auto-named FK indexes with stable IDX_* names where helpful.
        $this->addSql('ALTER TABLE assessment_attempts RENAME INDEX FK_AA_REVISION TO IDX_C3B1F642CB85B1B8');
        $this->addSql('ALTER TABLE assessment_attempts RENAME INDEX FK_AA_PUBLICATION_REVISION TO IDX_C3B1F642A77821CACB85B1B8');
        $this->addSql('ALTER TABLE assessment_attempt_items RENAME INDEX FK_AAI_REVISION TO IDX_34057EF8CB85B1B8');
        $this->addSql('ALTER TABLE assessment_attempt_items RENAME INDEX FK_AAI_ATTEMPT_REVISION TO IDX_34057EF8B191BE6BCB85B1B8');
        $this->addSql('ALTER TABLE assessment_attempt_items RENAME INDEX FK_AAI_SECTION_REVISION TO IDX_34057EF8714D3CDACB85B1B8');
        $this->addSql('ALTER TABLE assessment_attempt_items RENAME INDEX FK_AAI_ITEM_SECTION TO IDX_34057EF8B891C390714D3CDA');

        // B) BEFORE INSERT publication-graph validation + BU immutability including revision
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempt_items_bi_graph');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempt_items_bi_graph
            BEFORE INSERT ON assessment_attempt_items
            FOR EACH ROW
            BEGIN
                DECLARE section_revision_id BINARY(16);
                DECLARE item_section_id BINARY(16);
                DECLARE item_assessment_revision_id BINARY(16);
                DECLARE item_question_id BINARY(16);
                DECLARE item_question_revision_id BINARY(16);
                DECLARE attempt_revision_id BINARY(16);

                SELECT s.revision_id
                  INTO section_revision_id
                  FROM assessment_sections s
                 WHERE s.id = NEW.assessment_section_id
                 LIMIT 1;

                IF section_revision_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item section not found';
                END IF;

                IF section_revision_id <> NEW.assessment_revision_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item section revision mismatch';
                END IF;

                SELECT i.section_id, i.assessment_revision_id, i.question_id, i.question_revision_id
                  INTO item_section_id, item_assessment_revision_id, item_question_id, item_question_revision_id
                  FROM assessment_items i
                 WHERE i.id = NEW.assessment_item_id
                 LIMIT 1;

                IF item_section_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item assessment item not found';
                END IF;

                IF item_section_id <> NEW.assessment_section_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item section/item mismatch';
                END IF;

                IF item_assessment_revision_id <> NEW.assessment_revision_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item assessment revision mismatch';
                END IF;

                IF item_question_id <> NEW.question_id
                   OR item_question_revision_id <> NEW.question_revision_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item question revision mismatch';
                END IF;

                SELECT a.assessment_revision_id
                  INTO attempt_revision_id
                  FROM assessment_attempts a
                 WHERE a.id = NEW.attempt_id
                 LIMIT 1;

                IF attempt_revision_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item attempt not found';
                END IF;

                IF attempt_revision_id <> NEW.assessment_revision_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_item attempt revision mismatch';
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
                   OR OLD.assessment_revision_id <> NEW.assessment_revision_id
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

        // C) Guard sync — canonical ownership is DB triggers
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempts_ai_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempts_ai_guard
            AFTER INSERT ON assessment_attempts
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'in_progress' THEN
                    INSERT INTO assessment_attempt_active_guards (recipient_id, attempt_id, delivery_id)
                    VALUES (NEW.recipient_id, NEW.id, NEW.delivery_id);
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempts_au_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempts_au_guard
            AFTER UPDATE ON assessment_attempts
            FOR EACH ROW
            BEGIN
                DECLARE guard_exists INT DEFAULT 0;

                IF OLD.status = 'in_progress' AND NEW.status <> 'in_progress' THEN
                    DELETE FROM assessment_attempt_active_guards
                     WHERE attempt_id = NEW.id;
                END IF;

                IF NEW.status = 'in_progress' THEN
                    SELECT COUNT(*) INTO guard_exists
                      FROM assessment_attempt_active_guards g
                     WHERE g.attempt_id = NEW.id;

                    IF guard_exists = 0 THEN
                        INSERT INTO assessment_attempt_active_guards (recipient_id, attempt_id, delivery_id)
                        VALUES (NEW.recipient_id, NEW.id, NEW.delivery_id);
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempt_active_guards_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempt_active_guards_bd
            BEFORE DELETE ON assessment_attempt_active_guards
            FOR EACH ROW
            BEGIN
                DECLARE attempt_status VARCHAR(32);

                SELECT a.status
                  INTO attempt_status
                  FROM assessment_attempts a
                 WHERE a.id = OLD.attempt_id
                 LIMIT 1;

                IF attempt_status = 'in_progress' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot detach active attempt guard';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempt_active_guards_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempt_active_guards_bi
            BEFORE INSERT ON assessment_attempt_active_guards
            FOR EACH ROW
            BEGIN
                DECLARE attempt_status VARCHAR(32);
                DECLARE attempt_recipient_id BINARY(16);
                DECLARE attempt_delivery_id BINARY(16);

                SELECT a.status, a.recipient_id, a.delivery_id
                  INTO attempt_status, attempt_recipient_id, attempt_delivery_id
                  FROM assessment_attempts a
                 WHERE a.id = NEW.attempt_id
                 LIMIT 1;

                IF attempt_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_guard attempt not found';
                END IF;

                IF attempt_status <> 'in_progress' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_guard requires in_progress attempt';
                END IF;

                IF attempt_recipient_id <> NEW.recipient_id
                   OR attempt_delivery_id <> NEW.delivery_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_guard delivery/recipient mismatch';
                END IF;
            END
            SQL);

        // D) Attempt INSERT validation + BU identity includes assessment_revision_id
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempts_bi_scope');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempts_bi_scope
            BEFORE INSERT ON assessment_attempts
            FOR EACH ROW
            BEGIN
                DECLARE delivery_status VARCHAR(32);
                DECLARE delivery_institution_id BINARY(16);
                DECLARE delivery_assessment_id BINARY(16);
                DECLARE delivery_publication_id BINARY(16);
                DECLARE delivery_publication_number INT;
                DECLARE delivery_opens_at DATETIME;
                DECLARE delivery_closes_at DATETIME;
                DECLARE delivery_max_attempts INT;
                DECLARE recipient_status VARCHAR(32);
                DECLARE recipient_delivery_id BINARY(16);
                DECLARE recipient_institution_id BINARY(16);
                DECLARE recipient_membership_id BINARY(16);
                DECLARE recipient_user_id BINARY(16);
                DECLARE institution_status VARCHAR(32);
                DECLARE membership_status VARCHAR(32);
                DECLARE membership_role VARCHAR(32);
                DECLARE membership_institution_id BINARY(16);
                DECLARE membership_user_id BINARY(16);
                DECLARE user_status VARCHAR(32);
                DECLARE user_email_verified_at DATETIME;
                DECLARE pub_assessment_id BINARY(16);
                DECLARE pub_number INT;
                DECLARE pub_revision_id BINARY(16);
                DECLARE existing_attempt_count INT;

                IF NEW.status <> 'in_progress' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt insert requires in_progress status';
                END IF;

                IF NEW.submitted_at IS NOT NULL
                   OR NEW.expired_at IS NOT NULL
                   OR NEW.cancelled_at IS NOT NULL
                   OR NEW.cancelled_by_id IS NOT NULL
                   OR NEW.cancellation_reason_code IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt insert requires null terminal lifecycle fields';
                END IF;

                IF NEW.attempt_number < 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt attempt_number must be >= 1';
                END IF;

                IF NOT (NEW.started_at < NEW.expires_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt started_at must be before expires_at';
                END IF;

                SELECT d.status, d.institution_id, d.assessment_id, d.assessment_publication_id,
                       d.publication_number, d.opens_at, d.closes_at, d.max_attempts
                  INTO delivery_status, delivery_institution_id, delivery_assessment_id,
                       delivery_publication_id, delivery_publication_number, delivery_opens_at,
                       delivery_closes_at, delivery_max_attempts
                  FROM assessment_deliveries d
                 WHERE d.id = NEW.delivery_id
                 LIMIT 1;

                IF delivery_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt delivery not found';
                END IF;

                IF delivery_status <> 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt requires active delivery';
                END IF;

                IF delivery_institution_id <> NEW.institution_id
                   OR delivery_assessment_id <> NEW.assessment_id
                   OR delivery_publication_id <> NEW.assessment_publication_id
                   OR delivery_publication_number <> NEW.publication_number THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt delivery identity mismatch';
                END IF;

                IF NEW.started_at < delivery_opens_at OR NEW.expires_at > delivery_closes_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt window outside delivery opens/closes';
                END IF;

                SELECT r.status, r.delivery_id, r.institution_id, r.student_membership_id, r.user_id
                  INTO recipient_status, recipient_delivery_id, recipient_institution_id,
                       recipient_membership_id, recipient_user_id
                  FROM assessment_delivery_recipients r
                 WHERE r.id = NEW.recipient_id
                 LIMIT 1;

                IF recipient_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt recipient not found';
                END IF;

                IF recipient_status <> 'eligible' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt requires eligible recipient';
                END IF;

                IF recipient_delivery_id <> NEW.delivery_id
                   OR recipient_institution_id <> NEW.institution_id
                   OR recipient_membership_id <> NEW.student_membership_id
                   OR recipient_user_id <> NEW.user_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt recipient scope mismatch';
                END IF;

                SELECT i.status INTO institution_status
                  FROM institutions i
                 WHERE i.id = NEW.institution_id
                 LIMIT 1;

                IF institution_status IS NULL OR institution_status <> 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt requires active institution';
                END IF;

                SELECT m.status, m.role, m.institution_id, m.user_id
                  INTO membership_status, membership_role, membership_institution_id, membership_user_id
                  FROM institution_memberships m
                 WHERE m.id = NEW.student_membership_id
                 LIMIT 1;

                IF membership_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt membership not found';
                END IF;

                IF membership_status <> 'active'
                   OR membership_role <> 'student'
                   OR membership_institution_id <> NEW.institution_id
                   OR membership_user_id <> NEW.user_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt requires active student membership scope';
                END IF;

                SELECT u.status, u.email_verified_at
                  INTO user_status, user_email_verified_at
                  FROM users u
                 WHERE u.id = NEW.user_id
                 LIMIT 1;

                IF user_status IS NULL
                   OR user_status <> 'active'
                   OR user_email_verified_at IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt requires active verified user';
                END IF;

                SELECT ap.assessment_id, ap.publication_number, ap.assessment_revision_id
                  INTO pub_assessment_id, pub_number, pub_revision_id
                  FROM assessment_publications ap
                 WHERE ap.id = NEW.assessment_publication_id
                 LIMIT 1;

                IF pub_assessment_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt publication not found';
                END IF;

                IF pub_assessment_id <> NEW.assessment_id
                   OR pub_number <> NEW.publication_number
                   OR pub_revision_id <> NEW.assessment_revision_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt publication/revision chain mismatch';
                END IF;

                -- NOTE: COUNT alone is not race-safe; uniq_aa_active_recipient_scope + app locks are concurrency.
                SELECT COUNT(*) INTO existing_attempt_count
                  FROM assessment_attempts a
                 WHERE a.delivery_id = NEW.delivery_id
                   AND a.recipient_id = NEW.recipient_id;

                IF existing_attempt_count >= delivery_max_attempts THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt exceeds delivery max_attempts';
                END IF;
            END
            SQL);

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
                   OR OLD.assessment_revision_id <> NEW.assessment_revision_id
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

        // E) Answer BI/BU hardening
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_attempt_answers_bi_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_attempt_answers_bi_guard
            BEFORE INSERT ON assessment_attempt_answers
            FOR EACH ROW
            BEGIN
                DECLARE attempt_status VARCHAR(32);
                DECLARE attempt_expires_at DATETIME;

                SELECT a.status, a.expires_at
                  INTO attempt_status, attempt_expires_at
                  FROM assessment_attempts a
                 WHERE a.id = NEW.attempt_id
                 LIMIT 1;

                IF attempt_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer attempt not found';
                END IF;

                IF attempt_status <> 'in_progress' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer insert requires in_progress attempt';
                END IF;

                IF NOT (UTC_TIMESTAMP() < attempt_expires_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer insert requires unexpired attempt';
                END IF;

                IF NEW.client_revision <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer insert requires client_revision = 1';
                END IF;

                IF NEW.encryption_version < 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer encryption_version must be >= 1';
                END IF;

                IF OCTET_LENGTH(NEW.answer_nonce) <> 24 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer nonce must be 24 bytes';
                END IF;

                IF OCTET_LENGTH(NEW.answer_ciphertext) < 16 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer ciphertext too short';
                END IF;

                IF NEW.created_at <> NEW.answered_at OR NEW.updated_at <> NEW.answered_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer insert timestamps must match';
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
                DECLARE attempt_expires_at DATETIME;

                IF OLD.attempt_id <> NEW.attempt_id
                   OR OLD.attempt_item_id <> NEW.attempt_item_id
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer identity is immutable';
                END IF;

                SELECT a.status, a.expires_at
                  INTO attempt_status, attempt_expires_at
                  FROM assessment_attempts a
                 WHERE a.id = NEW.attempt_id
                 LIMIT 1;

                IF attempt_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer attempt not found';
                END IF;

                IF attempt_status <> 'in_progress' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer update requires in_progress attempt';
                END IF;

                IF NOT (UTC_TIMESTAMP() < attempt_expires_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer update requires unexpired attempt';
                END IF;

                IF NEW.client_revision <> OLD.client_revision + 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer client_revision must increment by 1';
                END IF;

                IF OCTET_LENGTH(NEW.answer_nonce) <> 24 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer nonce must be 24 bytes';
                END IF;

                IF NEW.answer_nonce = OLD.answer_nonce THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer nonce must change on update';
                END IF;

                IF OCTET_LENGTH(NEW.answer_ciphertext) < 16 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer ciphertext too short';
                END IF;

                IF NEW.answered_at < OLD.answered_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer answered_at may not move backward';
                END IF;

                IF NEW.updated_at < OLD.updated_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer updated_at may not move backward';
                END IF;

                IF NEW.updated_at < NEW.answered_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer updated_at must be >= answered_at';
                END IF;

                IF NEW.encryption_version < 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer encryption_version must be >= 1';
                END IF;

                IF NEW.encryption_version < OLD.encryption_version THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_attempt_answer encryption_version may not decrease';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Stage 2.11 assessment attempt integrity hardening is irreversible.',
        );
    }
}
