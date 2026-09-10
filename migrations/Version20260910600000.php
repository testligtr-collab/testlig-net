<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.10: assessment delivery + recipient DB integrity hardening.
 *
 * Irreversible security migration — down() does not restore weaker CHECKs/triggers.
 *
 * Does not modify Version20260910500000 or earlier.
 */
final class Version20260910600000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden assessment delivery lifecycle CHECKs and delivery/recipient insert/update triggers';
    }

    public function up(Schema $schema): void
    {
        $assessmentCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessments');
        $deliveryCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessment_deliveries');
        $recipientCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessment_delivery_recipients');

        $activeWithoutEligible = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_deliveries d
            WHERE d.status = 'active'
              AND NOT EXISTS (
                    SELECT 1 FROM assessment_delivery_recipients r
                     WHERE r.delivery_id = d.id AND r.status = 'eligible'
              )
            SQL);
        $this->abortIf(
            $activeWithoutEligible > 0,
            \sprintf(
                'Cannot harden deliveries: %d active delivery(ies) with zero eligible recipients (assessments=%d, deliveries=%d, recipients=%d). No silent delete.',
                $activeWithoutEligible,
                $assessmentCount,
                $deliveryCount,
                $recipientCount,
            ),
        );

        $lifecycleMismatch = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_deliveries d
            WHERE NOT (
                (
                    d.status = 'draft'
                    AND d.activated_at IS NULL AND d.activated_by_id IS NULL
                    AND d.closed_at IS NULL AND d.closed_by_id IS NULL
                    AND d.cancelled_at IS NULL AND d.cancelled_by_id IS NULL AND d.cancellation_reason_code IS NULL
                )
                OR (
                    d.status = 'active'
                    AND d.activated_at IS NOT NULL AND d.activated_by_id IS NOT NULL
                    AND d.closed_at IS NULL AND d.closed_by_id IS NULL
                    AND d.cancelled_at IS NULL AND d.cancelled_by_id IS NULL AND d.cancellation_reason_code IS NULL
                )
                OR (
                    d.status = 'closed'
                    AND d.activated_at IS NOT NULL AND d.activated_by_id IS NOT NULL
                    AND d.closed_at IS NOT NULL AND d.closed_by_id IS NOT NULL
                    AND d.cancelled_at IS NULL AND d.cancelled_by_id IS NULL AND d.cancellation_reason_code IS NULL
                    AND d.closed_at >= d.activated_at
                )
                OR (
                    d.status = 'cancelled'
                    AND d.cancelled_at IS NOT NULL AND d.cancelled_by_id IS NOT NULL AND d.cancellation_reason_code IS NOT NULL
                    AND d.closed_at IS NULL AND d.closed_by_id IS NULL
                    AND (
                        (d.activated_at IS NULL AND d.activated_by_id IS NULL)
                        OR (
                            d.activated_at IS NOT NULL AND d.activated_by_id IS NOT NULL
                            AND d.cancelled_at >= d.activated_at
                        )
                    )
                )
            )
            SQL);
        $this->abortIf(
            $lifecycleMismatch > 0,
            \sprintf(
                'Cannot harden deliveries: %d delivery(ies) violate status/lifecycle field model (assessments=%d, deliveries=%d, recipients=%d). No silent delete.',
                $lifecycleMismatch,
                $assessmentCount,
                $deliveryCount,
                $recipientCount,
            ),
        );

        $wrongLineage = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_delivery_recipients r
            INNER JOIN assessment_deliveries d ON d.id = r.delivery_id
            WHERE (
                d.audience_type = 'institution'
                AND (
                    d.classroom_id IS NOT NULL
                    OR d.student_membership_id IS NOT NULL
                    OR r.source_classroom_id IS NOT NULL
                    OR r.source_enrollment_id IS NOT NULL
                )
            )
            OR (
                d.audience_type = 'classroom'
                AND (
                    d.classroom_id IS NULL
                    OR r.source_classroom_id IS NULL
                    OR r.source_classroom_id <> d.classroom_id
                    OR r.source_enrollment_id IS NULL
                    OR NOT EXISTS (
                        SELECT 1 FROM classroom_student_enrollments e
                         WHERE e.id = r.source_enrollment_id
                           AND e.classroom_id = r.source_classroom_id
                           AND e.student_membership_id = r.student_membership_id
                           AND e.institution_id = d.institution_id
                    )
                )
            )
            OR (
                d.audience_type = 'student'
                AND (
                    d.student_membership_id IS NULL
                    OR r.student_membership_id <> d.student_membership_id
                    OR r.source_classroom_id IS NOT NULL
                    OR r.source_enrollment_id IS NOT NULL
                )
            )
            SQL);
        $this->abortIf(
            $wrongLineage > 0,
            \sprintf(
                'Cannot harden deliveries: %d recipient(s) have wrong audience source lineage (assessments=%d, deliveries=%d, recipients=%d). No silent delete.',
                $wrongLineage,
                $assessmentCount,
                $deliveryCount,
                $recipientCount,
            ),
        );

        $badMembershipRecipients = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_delivery_recipients r
            INNER JOIN institution_memberships m ON m.id = r.student_membership_id
            WHERE m.role <> 'student'
               OR m.status <> 'active'
               OR m.institution_id <> r.institution_id
               OR m.user_id <> r.user_id
            SQL);
        $this->abortIf(
            $badMembershipRecipients > 0,
            \sprintf(
                'Cannot harden deliveries: %d recipient(s) have non-student or inactive membership lineage (assessments=%d, deliveries=%d, recipients=%d). No silent delete.',
                $badMembershipRecipients,
                $assessmentCount,
                $deliveryCount,
                $recipientCount,
            ),
        );

        $this->addSql('ALTER TABLE assessment_deliveries DROP CONSTRAINT chk_ad_activated_pair');
        $this->addSql('ALTER TABLE assessment_deliveries DROP CONSTRAINT chk_ad_closed_pair');
        $this->addSql('ALTER TABLE assessment_deliveries DROP CONSTRAINT chk_ad_cancelled_pair');

        $this->addSql(<<<'SQL'
            ALTER TABLE assessment_deliveries
                ADD CONSTRAINT chk_ad_lifecycle_fields CHECK (
                    (
                        status = 'draft'
                        AND activated_at IS NULL AND activated_by_id IS NULL
                        AND closed_at IS NULL AND closed_by_id IS NULL
                        AND cancelled_at IS NULL AND cancelled_by_id IS NULL AND cancellation_reason_code IS NULL
                    )
                    OR (
                        status = 'active'
                        AND activated_at IS NOT NULL AND activated_by_id IS NOT NULL
                        AND closed_at IS NULL AND closed_by_id IS NULL
                        AND cancelled_at IS NULL AND cancelled_by_id IS NULL AND cancellation_reason_code IS NULL
                    )
                    OR (
                        status = 'closed'
                        AND activated_at IS NOT NULL AND activated_by_id IS NOT NULL
                        AND closed_at IS NOT NULL AND closed_by_id IS NOT NULL
                        AND cancelled_at IS NULL AND cancelled_by_id IS NULL AND cancellation_reason_code IS NULL
                        AND closed_at >= activated_at
                    )
                    OR (
                        status = 'cancelled'
                        AND cancelled_at IS NOT NULL AND cancelled_by_id IS NOT NULL AND cancellation_reason_code IS NOT NULL
                        AND closed_at IS NULL AND closed_by_id IS NULL
                        AND (
                            (activated_at IS NULL AND activated_by_id IS NULL)
                            OR (
                                activated_at IS NOT NULL AND activated_by_id IS NOT NULL
                                AND cancelled_at >= activated_at
                            )
                        )
                    )
                )
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_deliveries_bu_identity');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_deliveries_bu_identity
            BEFORE UPDATE ON assessment_deliveries
            FOR EACH ROW
            BEGIN
                DECLARE eligible_count INT DEFAULT 0;

                IF OLD.assessment_publication_id <> NEW.assessment_publication_id
                   OR OLD.assessment_id <> NEW.assessment_id
                   OR OLD.publication_number <> NEW.publication_number
                   OR OLD.institution_id <> NEW.institution_id
                   OR OLD.audience_type <> NEW.audience_type
                   OR NOT (OLD.classroom_id <=> NEW.classroom_id)
                   OR NOT (OLD.student_membership_id <=> NEW.student_membership_id)
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

                IF NOT (
                    (OLD.status = 'draft' AND NEW.status IN ('draft', 'active', 'cancelled'))
                    OR (OLD.status = 'active' AND NEW.status IN ('active', 'closed', 'cancelled'))
                    OR (OLD.status = 'closed' AND NEW.status = 'closed')
                    OR (OLD.status = 'cancelled' AND NEW.status = 'cancelled')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery status transition is not allowed';
                END IF;

                IF OLD.status = NEW.status THEN
                    IF NOT (OLD.activated_at <=> NEW.activated_at)
                       OR NOT (OLD.activated_by_id <=> NEW.activated_by_id)
                       OR NOT (OLD.closed_at <=> NEW.closed_at)
                       OR NOT (OLD.closed_by_id <=> NEW.closed_by_id)
                       OR NOT (OLD.cancelled_at <=> NEW.cancelled_at)
                       OR NOT (OLD.cancelled_by_id <=> NEW.cancelled_by_id)
                       OR NOT (OLD.cancellation_reason_code <=> NEW.cancellation_reason_code)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery lifecycle fields immutable when status unchanged';
                    END IF;
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'active' THEN
                    IF NEW.activated_at IS NULL OR NEW.activated_by_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery activate requires activated fields';
                    END IF;
                    IF OLD.activated_at IS NOT NULL OR OLD.activated_by_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery activated fields already set';
                    END IF;
                    IF NEW.closed_at IS NOT NULL OR NEW.closed_by_id IS NOT NULL
                       OR NEW.cancelled_at IS NOT NULL OR NEW.cancelled_by_id IS NOT NULL
                       OR NEW.cancellation_reason_code IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery activate forbids closed or cancelled fields';
                    END IF;

                    SELECT COUNT(*) INTO eligible_count
                      FROM assessment_delivery_recipients r
                     WHERE r.delivery_id = NEW.id AND r.status = 'eligible';
                    IF eligible_count < 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery activate requires eligible recipients';
                    END IF;
                ELSEIF OLD.status = 'active' AND NEW.status = 'closed' THEN
                    IF NEW.closed_at IS NULL OR NEW.closed_by_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery close requires closed fields';
                    END IF;
                    IF OLD.closed_at IS NOT NULL OR OLD.closed_by_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery closed fields already set';
                    END IF;
                    IF NOT (OLD.activated_at <=> NEW.activated_at)
                       OR NOT (OLD.activated_by_id <=> NEW.activated_by_id)
                       OR NEW.activated_at IS NULL
                       OR NEW.activated_by_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery close must keep activated fields';
                    END IF;
                    IF NEW.cancelled_at IS NOT NULL OR NEW.cancelled_by_id IS NOT NULL
                       OR NEW.cancellation_reason_code IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery close forbids cancelled fields';
                    END IF;
                ELSEIF (OLD.status = 'draft' OR OLD.status = 'active') AND NEW.status = 'cancelled' THEN
                    IF NEW.cancelled_at IS NULL OR NEW.cancelled_by_id IS NULL OR NEW.cancellation_reason_code IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery cancel requires cancel fields';
                    END IF;
                    IF OLD.cancelled_at IS NOT NULL OR OLD.cancelled_by_id IS NOT NULL
                       OR OLD.cancellation_reason_code IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery cancel fields already set';
                    END IF;
                    IF NEW.closed_at IS NOT NULL OR NEW.closed_by_id IS NOT NULL
                       OR OLD.closed_at IS NOT NULL OR OLD.closed_by_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery cancel forbids closed fields';
                    END IF;
                    IF NOT (OLD.activated_at <=> NEW.activated_at)
                       OR NOT (OLD.activated_by_id <=> NEW.activated_by_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery cancel must keep activated fields';
                    END IF;
                ELSEIF OLD.status <> NEW.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery status transition is not allowed';
                ELSE
                    IF NOT (OLD.activated_at <=> NEW.activated_at)
                       OR NOT (OLD.activated_by_id <=> NEW.activated_by_id)
                       OR NOT (OLD.closed_at <=> NEW.closed_at)
                       OR NOT (OLD.closed_by_id <=> NEW.closed_by_id)
                       OR NOT (OLD.cancelled_at <=> NEW.cancelled_at)
                       OR NOT (OLD.cancelled_by_id <=> NEW.cancelled_by_id)
                       OR NOT (OLD.cancellation_reason_code <=> NEW.cancellation_reason_code) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery lifecycle fields may change only on allowed transitions';
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_deliveries_bi_scope');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_deliveries_bi_scope
            BEFORE INSERT ON assessment_deliveries
            FOR EACH ROW
            BEGIN
                DECLARE pub_assessment_id BINARY(16);
                DECLARE pub_number INT;
                DECLARE assessment_status VARCHAR(32);
                DECLARE assessment_scope VARCHAR(32);
                DECLARE assessment_institution_id BINARY(16);
                DECLARE assessment_published_revision_id BINARY(16);
                DECLARE institution_status VARCHAR(32);
                DECLARE classroom_institution_id BINARY(16);
                DECLARE classroom_status VARCHAR(32);
                DECLARE classroom_year_id BINARY(16);
                DECLARE year_status VARCHAR(32);
                DECLARE membership_institution_id BINARY(16);
                DECLARE membership_role VARCHAR(32);
                DECLARE membership_status VARCHAR(32);
                DECLARE membership_user_id BINARY(16);
                DECLARE target_user_status VARCHAR(32);
                DECLARE target_user_verified DATETIME;

                IF NEW.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery insert requires draft status';
                END IF;

                IF NEW.activated_at IS NOT NULL OR NEW.activated_by_id IS NOT NULL
                   OR NEW.closed_at IS NOT NULL OR NEW.closed_by_id IS NOT NULL
                   OR NEW.cancelled_at IS NOT NULL OR NEW.cancelled_by_id IS NOT NULL
                   OR NEW.cancellation_reason_code IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery insert requires null lifecycle fields';
                END IF;

                SELECT ap.assessment_id, ap.publication_number
                  INTO pub_assessment_id, pub_number
                  FROM assessment_publications ap
                 WHERE ap.id = NEW.assessment_publication_id
                 LIMIT 1;

                IF pub_assessment_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery publication not found';
                END IF;

                IF pub_assessment_id <> NEW.assessment_id OR pub_number <> NEW.publication_number THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery publication identity mismatch';
                END IF;

                SELECT a.status, a.scope, a.institution_id, a.published_revision_id
                  INTO assessment_status, assessment_scope, assessment_institution_id, assessment_published_revision_id
                  FROM assessments a
                 WHERE a.id = NEW.assessment_id
                 LIMIT 1;

                IF assessment_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery assessment not found';
                END IF;

                IF assessment_status = 'archived' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery rejects archived assessment';
                END IF;

                IF assessment_status <> 'published' OR assessment_published_revision_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery requires published assessment revision';
                END IF;

                SELECT i.status INTO institution_status
                  FROM institutions i
                 WHERE i.id = NEW.institution_id
                 LIMIT 1;

                IF institution_status IS NULL OR institution_status <> 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery requires active institution';
                END IF;

                IF assessment_scope = 'institution' THEN
                    IF assessment_institution_id IS NULL OR assessment_institution_id <> NEW.institution_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery institution scope mismatch';
                    END IF;
                ELSEIF assessment_scope = 'platform' THEN
                    IF institution_status <> 'active' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery platform requires active institution';
                    END IF;
                ELSE
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery assessment scope invalid';
                END IF;

                IF NEW.audience_type = 'classroom' THEN
                    IF NEW.classroom_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery classroom audience requires classroom';
                    END IF;

                    SELECT c.institution_id, c.status, c.academic_year_id
                      INTO classroom_institution_id, classroom_status, classroom_year_id
                      FROM classrooms c
                     WHERE c.id = NEW.classroom_id
                     LIMIT 1;

                    IF classroom_institution_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery classroom not found';
                    END IF;

                    IF classroom_institution_id <> NEW.institution_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery classroom institution mismatch';
                    END IF;

                    IF classroom_status <> 'active' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery classroom must be active';
                    END IF;

                    SELECT y.status INTO year_status
                      FROM academic_years y
                     WHERE y.id = classroom_year_id
                     LIMIT 1;

                    IF year_status IS NULL OR year_status <> 'active' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery academic year must be active';
                    END IF;
                END IF;

                IF NEW.audience_type = 'student' THEN
                    IF NEW.student_membership_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery student audience requires membership';
                    END IF;

                    SELECT m.institution_id, m.role, m.status, m.user_id
                      INTO membership_institution_id, membership_role, membership_status, membership_user_id
                      FROM institution_memberships m
                     WHERE m.id = NEW.student_membership_id
                     LIMIT 1;

                    IF membership_institution_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery student membership not found';
                    END IF;

                    IF membership_institution_id <> NEW.institution_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery student membership institution mismatch';
                    END IF;

                    IF membership_role <> 'student' OR membership_status <> 'active' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery student membership must be active student';
                    END IF;

                    SELECT u.status, u.email_verified_at
                      INTO target_user_status, target_user_verified
                      FROM users u
                     WHERE u.id = membership_user_id
                     LIMIT 1;

                    IF target_user_status IS NULL OR target_user_status <> 'active' OR target_user_verified IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery student user must be active and verified';
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

                IF NOT (
                    (OLD.status = 'eligible' AND NEW.status IN ('eligible', 'revoked'))
                    OR (OLD.status = 'revoked' AND NEW.status = 'revoked')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery_recipient status transition is not allowed';
                END IF;

                IF NEW.status = 'eligible' THEN
                    IF NEW.revoked_at IS NOT NULL OR NEW.revoked_by_id IS NOT NULL OR NEW.revocation_reason_code IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'eligible recipient requires null revoke fields';
                    END IF;
                END IF;

                IF NEW.status = 'revoked' THEN
                    IF NEW.revoked_at IS NULL OR NEW.revoked_by_id IS NULL OR NEW.revocation_reason_code IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revoked recipient requires revoke fields';
                    END IF;
                END IF;

                IF OLD.status = 'eligible' AND NEW.status = 'revoked' THEN
                    IF OLD.revoked_at IS NOT NULL OR OLD.revoked_by_id IS NOT NULL OR OLD.revocation_reason_code IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient revoke fields already set';
                    END IF;
                ELSEIF OLD.status = NEW.status THEN
                    IF NOT (OLD.revoked_at <=> NEW.revoked_at)
                       OR NOT (OLD.revoked_by_id <=> NEW.revoked_by_id)
                       OR NOT (OLD.revocation_reason_code <=> NEW.revocation_reason_code) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient revoke fields immutable when status unchanged';
                    END IF;
                ELSE
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_delivery_recipient status transition is not allowed';
                END IF;

                IF OLD.status = 'revoked' THEN
                    IF NOT (OLD.revoked_at <=> NEW.revoked_at)
                       OR NOT (OLD.revoked_by_id <=> NEW.revoked_by_id)
                       OR NOT (OLD.revocation_reason_code <=> NEW.revocation_reason_code) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revoked recipient revoke fields are immutable';
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_delivery_recipients_bi_eligibility');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_delivery_recipients_bi_eligibility
            BEFORE INSERT ON assessment_delivery_recipients
            FOR EACH ROW
            BEGIN
                DECLARE delivery_status VARCHAR(32);
                DECLARE delivery_institution_id BINARY(16);
                DECLARE delivery_audience VARCHAR(32);
                DECLARE delivery_classroom_id BINARY(16);
                DECLARE delivery_student_membership_id BINARY(16);
                DECLARE institution_status VARCHAR(32);
                DECLARE membership_institution_id BINARY(16);
                DECLARE membership_user_id BINARY(16);
                DECLARE membership_role VARCHAR(32);
                DECLARE membership_status VARCHAR(32);
                DECLARE user_status VARCHAR(32);
                DECLARE user_verified DATETIME;
                DECLARE enrollment_classroom_id BINARY(16);
                DECLARE enrollment_membership_id BINARY(16);
                DECLARE enrollment_institution_id BINARY(16);
                DECLARE enrollment_status VARCHAR(32);
                DECLARE classroom_status VARCHAR(32);
                DECLARE classroom_year_id BINARY(16);
                DECLARE year_status VARCHAR(32);

                IF NEW.status <> 'eligible' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient insert requires eligible status';
                END IF;

                IF NEW.revoked_at IS NOT NULL OR NEW.revoked_by_id IS NOT NULL OR NEW.revocation_reason_code IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient insert requires null revoke fields';
                END IF;

                SELECT d.status, d.institution_id, d.audience_type, d.classroom_id, d.student_membership_id
                  INTO delivery_status, delivery_institution_id, delivery_audience, delivery_classroom_id, delivery_student_membership_id
                  FROM assessment_deliveries d
                 WHERE d.id = NEW.delivery_id
                 LIMIT 1;

                IF delivery_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient delivery not found';
                END IF;

                IF delivery_status NOT IN ('draft', 'active') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient insert requires draft or active delivery';
                END IF;

                IF NEW.institution_id <> delivery_institution_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient institution must match delivery';
                END IF;

                SELECT i.status INTO institution_status
                  FROM institutions i
                 WHERE i.id = NEW.institution_id
                 LIMIT 1;

                IF institution_status IS NULL OR institution_status <> 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient requires active institution';
                END IF;

                SELECT m.institution_id, m.user_id, m.role, m.status
                  INTO membership_institution_id, membership_user_id, membership_role, membership_status
                  FROM institution_memberships m
                 WHERE m.id = NEW.student_membership_id
                 LIMIT 1;

                IF membership_institution_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient membership not found';
                END IF;

                IF membership_institution_id <> NEW.institution_id
                   OR membership_user_id <> NEW.user_id
                   OR membership_role <> 'student'
                   OR membership_status <> 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient requires active student membership';
                END IF;

                SELECT u.status, u.email_verified_at
                  INTO user_status, user_verified
                  FROM users u
                 WHERE u.id = NEW.user_id
                 LIMIT 1;

                IF user_status IS NULL OR user_status <> 'active' OR user_verified IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient requires active verified user';
                END IF;

                IF delivery_audience = 'institution' THEN
                    IF delivery_classroom_id IS NOT NULL OR delivery_student_membership_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'institution audience delivery targets invalid';
                    END IF;
                    IF NEW.source_classroom_id IS NOT NULL OR NEW.source_enrollment_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'institution audience recipient sources must be null';
                    END IF;
                ELSEIF delivery_audience = 'classroom' THEN
                    IF delivery_classroom_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'classroom audience delivery missing classroom';
                    END IF;
                    IF NEW.source_classroom_id IS NULL OR NEW.source_classroom_id <> delivery_classroom_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'classroom audience source classroom mismatch';
                    END IF;
                    IF NEW.source_enrollment_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'classroom audience requires source enrollment';
                    END IF;

                    SELECT e.classroom_id, e.student_membership_id, e.institution_id, e.status
                      INTO enrollment_classroom_id, enrollment_membership_id, enrollment_institution_id, enrollment_status
                      FROM classroom_student_enrollments e
                     WHERE e.id = NEW.source_enrollment_id
                     LIMIT 1;

                    IF enrollment_classroom_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient source enrollment not found';
                    END IF;

                    IF enrollment_classroom_id <> NEW.source_classroom_id
                       OR enrollment_membership_id <> NEW.student_membership_id
                       OR enrollment_institution_id <> NEW.institution_id
                       OR enrollment_status <> 'active' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient enrollment lineage invalid';
                    END IF;

                    SELECT c.status, c.academic_year_id
                      INTO classroom_status, classroom_year_id
                      FROM classrooms c
                     WHERE c.id = NEW.source_classroom_id
                     LIMIT 1;

                    IF classroom_status IS NULL OR classroom_status <> 'active' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient source classroom must be active';
                    END IF;

                    SELECT y.status INTO year_status
                      FROM academic_years y
                     WHERE y.id = classroom_year_id
                     LIMIT 1;

                    IF year_status IS NULL OR year_status <> 'active' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient academic year must be active';
                    END IF;
                ELSEIF delivery_audience = 'student' THEN
                    IF delivery_student_membership_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student audience delivery missing membership';
                    END IF;
                    IF NEW.student_membership_id <> delivery_student_membership_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student audience membership mismatch';
                    END IF;
                    IF NEW.source_classroom_id IS NOT NULL OR NEW.source_enrollment_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student audience recipient sources must be null';
                    END IF;
                ELSE
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient delivery audience invalid';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Stage 2.10 assessment delivery integrity hardening is irreversible.',
        );
    }
}
