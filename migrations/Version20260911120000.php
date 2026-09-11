<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.12 pre-merge hardening: MariaDB trigger enforcement for scoring runs,
 * item scores, manual decisions, and result releases.
 *
 * Irreversible security migration — down() does not restore weaker triggers.
 *
 * Does not modify Version20260910900000 or any older migrations.
 */
final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden assessment scoring triggers: insert shape, aggregate match, sequential numbers, manual decisions';
    }

    public function up(Schema $schema): void
    {
        $required = [
            'assessment_scoring_runs',
            'assessment_item_scores',
            'assessment_manual_grade_decisions',
            'assessment_result_releases',
            'assessment_attempt_items',
        ];
        foreach ($required as $tableName) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($tableName),
            );
            $this->abortIf(
                0 === $exists,
                \sprintf('Version20260911120000 requires table %s from prior Stage 2.12 migrations.', $tableName),
            );
        }

        // Preflight: processing rows must remain insert-shaped (zeros, no completed_at).
        $badProcessing = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_scoring_runs r
             WHERE r.status = 'processing'
               AND (
                    r.raw_points <> 0
                    OR r.final_points <> 0
                    OR r.maximum_points <> 0
                    OR r.percentage <> 0
                    OR r.correct_count <> 0
                    OR r.incorrect_count <> 0
                    OR r.unanswered_count <> 0
                    OR r.manual_pending_count <> 0
                    OR r.completed_at IS NOT NULL
               )
            SQL);
        $this->abortIf(
            $badProcessing > 0,
            \sprintf(
                'Cannot harden scoring triggers: %d processing scoring_run(s) have non-zero aggregates or completed_at.',
                $badProcessing,
            ),
        );

        // Preflight: completed/pending_manual must have full item coverage and matching aggregates.
        $badTerminal = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_scoring_runs r
             WHERE r.status IN ('completed', 'pending_manual')
               AND (
                    (SELECT COUNT(*) FROM assessment_item_scores s WHERE s.scoring_run_id = r.id)
                    <>
                    (SELECT COUNT(*) FROM assessment_attempt_items ai WHERE ai.attempt_id = r.attempt_id)
                    OR EXISTS (
                        SELECT 1 FROM assessment_item_scores s
                         WHERE s.scoring_run_id = r.id
                           AND s.attempt_id <> r.attempt_id
                    )
                    OR EXISTS (
                        SELECT 1 FROM assessment_attempt_items ai
                         WHERE ai.attempt_id = r.attempt_id
                           AND NOT EXISTS (
                               SELECT 1 FROM assessment_item_scores s
                                WHERE s.scoring_run_id = r.id
                                  AND s.attempt_item_id = ai.id
                           )
                    )
                    OR r.maximum_points <= 0
                    OR CAST(r.raw_points AS DECIMAL(12, 2)) <> CAST((
                        SELECT COALESCE(SUM(s.awarded_points), 0)
                          FROM assessment_item_scores s
                         WHERE s.scoring_run_id = r.id
                    ) AS DECIMAL(12, 2))
                    OR CAST(r.maximum_points AS DECIMAL(12, 2)) <> CAST((
                        SELECT COALESCE(SUM(s.maximum_points), 0)
                          FROM assessment_item_scores s
                         WHERE s.scoring_run_id = r.id
                    ) AS DECIMAL(12, 2))
                    OR CAST(r.final_points AS DECIMAL(12, 2)) <> CAST(
                        IF(r.raw_points < 0, 0, r.raw_points) AS DECIMAL(12, 2)
                    )
                    OR CAST(r.percentage AS DECIMAL(7, 4)) <> CAST(
                        TRUNCATE(
                            (CAST(r.final_points AS DECIMAL(20, 8))
                             / CAST(r.maximum_points AS DECIMAL(20, 8))) * 100,
                            4
                        ) AS DECIMAL(7, 4)
                    )
                    OR r.correct_count <> (
                        SELECT COUNT(*) FROM assessment_item_scores s
                         WHERE s.scoring_run_id = r.id AND s.outcome = 'correct'
                    )
                    OR r.incorrect_count <> (
                        SELECT COUNT(*) FROM assessment_item_scores s
                         WHERE s.scoring_run_id = r.id
                           AND s.outcome IN ('incorrect', 'invalid')
                    )
                    OR r.unanswered_count <> (
                        SELECT COUNT(*) FROM assessment_item_scores s
                         WHERE s.scoring_run_id = r.id AND s.outcome = 'unanswered'
                    )
                    OR r.manual_pending_count <> (
                        SELECT COUNT(*) FROM assessment_item_scores s
                         WHERE s.scoring_run_id = r.id
                           AND (s.outcome = 'manual_pending' OR s.manual_pending = 1)
                    )
                    OR (
                        r.status = 'completed'
                        AND (r.manual_pending_count <> 0 OR r.completed_at IS NULL)
                    )
                    OR (
                        r.status = 'pending_manual'
                        AND (r.manual_pending_count < 1 OR r.completed_at IS NOT NULL)
                    )
               )
            SQL);
        $this->abortIf(
            $badTerminal > 0,
            \sprintf(
                'Cannot harden scoring triggers: %d completed/pending_manual scoring_run(s) fail aggregate or coverage checks.',
                $badTerminal,
            ),
        );

        // A) BEFORE INSERT scoring run: attempt status + insert shape + sequential run_number
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_scoring_runs_bi_attempt');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_scoring_runs_bi_attempt
            BEFORE INSERT ON assessment_scoring_runs
            FOR EACH ROW
            BEGIN
                DECLARE attempt_status VARCHAR(32);
                DECLARE max_run_number INT DEFAULT 0;

                SELECT a.status INTO attempt_status
                  FROM assessment_attempts a
                 WHERE a.id = NEW.attempt_id
                 LIMIT 1;

                IF attempt_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run attempt not found';
                END IF;

                IF attempt_status NOT IN ('submitted', 'expired') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run requires submitted or expired attempt';
                END IF;

                IF NEW.status <> 'processing' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run insert requires processing status';
                END IF;

                IF NEW.raw_points <> 0
                   OR NEW.final_points <> 0
                   OR NEW.maximum_points <> 0
                   OR NEW.percentage <> 0
                   OR NEW.correct_count <> 0
                   OR NEW.incorrect_count <> 0
                   OR NEW.unanswered_count <> 0
                   OR NEW.manual_pending_count <> 0
                   OR NEW.completed_at IS NOT NULL
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run insert requires zero aggregates';
                END IF;

                SELECT COALESCE(MAX(r.run_number), 0) INTO max_run_number
                  FROM assessment_scoring_runs r
                 WHERE r.attempt_id = NEW.attempt_id;

                IF NEW.run_number <> (max_run_number + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run run_number must be sequential';
                END IF;
            END
            SQL);

        // B) BEFORE UPDATE scoring run: identity + lifecycle + aggregate validation
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_scoring_runs_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_scoring_runs_bu_immutable
            BEFORE UPDATE ON assessment_scoring_runs
            FOR EACH ROW
            BEGIN
                DECLARE attempt_item_count INT DEFAULT 0;
                DECLARE score_count INT DEFAULT 0;
                DECLARE missing_item_count INT DEFAULT 0;
                DECLARE wrong_attempt_count INT DEFAULT 0;
                DECLARE sum_awarded DECIMAL(12, 2) DEFAULT 0.00;
                DECLARE sum_maximum DECIMAL(12, 2) DEFAULT 0.00;
                DECLARE expected_final DECIMAL(12, 2) DEFAULT 0.00;
                DECLARE expected_pct DECIMAL(7, 4) DEFAULT 0.0000;
                DECLARE cnt_correct INT DEFAULT 0;
                DECLARE cnt_incorrect INT DEFAULT 0;
                DECLARE cnt_unanswered INT DEFAULT 0;
                DECLARE cnt_manual_pending INT DEFAULT 0;

                IF OLD.attempt_id <> NEW.attempt_id
                   OR OLD.institution_id <> NEW.institution_id
                   OR OLD.delivery_id <> NEW.delivery_id
                   OR OLD.recipient_id <> NEW.recipient_id
                   OR OLD.user_id <> NEW.user_id
                   OR OLD.assessment_id <> NEW.assessment_id
                   OR OLD.assessment_publication_id <> NEW.assessment_publication_id
                   OR OLD.assessment_revision_id <> NEW.assessment_revision_id
                   OR OLD.publication_number <> NEW.publication_number
                   OR OLD.scoring_policy_id <> NEW.scoring_policy_id
                   OR OLD.scoring_version <> NEW.scoring_version
                   OR OLD.run_number <> NEW.run_number
                   OR OLD.started_at <> NEW.started_at
                   OR NOT (OLD.created_by_id <=> NEW.created_by_id)
                   OR OLD.reason_code <> NEW.reason_code
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run identity is immutable';
                END IF;

                IF OLD.status = 'completed' OR OLD.status = 'failed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'completed or failed scoring_run is immutable';
                END IF;

                IF NOT (
                    (OLD.status = 'processing' AND NEW.status IN ('processing', 'pending_manual', 'completed', 'failed'))
                    OR (OLD.status = 'pending_manual' AND NEW.status IN ('pending_manual', 'completed', 'failed'))
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run status transition is not allowed';
                END IF;

                IF NEW.status IN ('pending_manual', 'completed') THEN
                    SELECT COUNT(*) INTO attempt_item_count
                      FROM assessment_attempt_items ai
                     WHERE ai.attempt_id = NEW.attempt_id;

                    SELECT COUNT(*) INTO score_count
                      FROM assessment_item_scores s
                     WHERE s.scoring_run_id = NEW.id;

                    IF score_count <> attempt_item_count THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run item coverage mismatch';
                    END IF;

                    SELECT COUNT(*) INTO missing_item_count
                      FROM assessment_attempt_items ai
                     WHERE ai.attempt_id = NEW.attempt_id
                       AND NOT EXISTS (
                           SELECT 1 FROM assessment_item_scores s
                            WHERE s.scoring_run_id = NEW.id
                              AND s.attempt_item_id = ai.id
                       );

                    IF missing_item_count <> 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run missing item_score coverage';
                    END IF;

                    SELECT COUNT(*) INTO wrong_attempt_count
                      FROM assessment_item_scores s
                     WHERE s.scoring_run_id = NEW.id
                       AND s.attempt_id <> NEW.attempt_id;

                    IF wrong_attempt_count <> 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run item_score attempt mismatch';
                    END IF;

                    SELECT COALESCE(SUM(s.awarded_points), 0),
                           COALESCE(SUM(s.maximum_points), 0),
                           SUM(CASE WHEN s.outcome = 'correct' THEN 1 ELSE 0 END),
                           SUM(CASE WHEN s.outcome IN ('incorrect', 'invalid') THEN 1 ELSE 0 END),
                           SUM(CASE WHEN s.outcome = 'unanswered' THEN 1 ELSE 0 END),
                           SUM(CASE WHEN s.outcome = 'manual_pending' OR s.manual_pending = 1 THEN 1 ELSE 0 END)
                      INTO sum_awarded, sum_maximum, cnt_correct, cnt_incorrect, cnt_unanswered, cnt_manual_pending
                      FROM assessment_item_scores s
                     WHERE s.scoring_run_id = NEW.id;

                    IF NEW.maximum_points <= 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run maximum_points must be positive';
                    END IF;

                    IF CAST(NEW.raw_points AS DECIMAL(12, 2)) <> CAST(sum_awarded AS DECIMAL(12, 2)) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run raw_points mismatch';
                    END IF;

                    IF CAST(NEW.maximum_points AS DECIMAL(12, 2)) <> CAST(sum_maximum AS DECIMAL(12, 2)) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run maximum_points mismatch';
                    END IF;

                    SET expected_final = CAST(IF(NEW.raw_points < 0, 0, NEW.raw_points) AS DECIMAL(12, 2));
                    IF CAST(NEW.final_points AS DECIMAL(12, 2)) <> expected_final THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run final_points mismatch';
                    END IF;

                    SET expected_pct = CAST(
                        TRUNCATE(
                            (CAST(NEW.final_points AS DECIMAL(20, 8))
                             / CAST(NEW.maximum_points AS DECIMAL(20, 8))) * 100,
                            4
                        ) AS DECIMAL(7, 4)
                    );
                    IF CAST(NEW.percentage AS DECIMAL(7, 4)) <> expected_pct THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run percentage mismatch';
                    END IF;

                    IF NEW.correct_count <> cnt_correct
                       OR NEW.incorrect_count <> cnt_incorrect
                       OR NEW.unanswered_count <> cnt_unanswered
                       OR NEW.manual_pending_count <> cnt_manual_pending
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'scoring_run counters mismatch';
                    END IF;

                    IF NEW.status = 'completed' THEN
                        IF NEW.manual_pending_count <> 0 OR NEW.completed_at IS NULL THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'completed scoring_run requires no manual pending';
                        END IF;
                    END IF;

                    IF NEW.status = 'pending_manual' THEN
                        IF NEW.manual_pending_count < 1 OR NEW.completed_at IS NOT NULL THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pending_manual scoring_run requires open manual work';
                        END IF;
                    END IF;
                END IF;
            END
            SQL);

        // C) BEFORE INSERT result release: completed run + aggregates + sequential release_number
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_releases_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_releases_bi
            BEFORE INSERT ON assessment_result_releases
            FOR EACH ROW
            BEGIN
                DECLARE run_status VARCHAR(32);
                DECLARE run_attempt_id BINARY(16);
                DECLARE run_completed_at DATETIME;
                DECLARE run_raw DECIMAL(12, 2);
                DECLARE run_final DECIMAL(12, 2);
                DECLARE run_maximum DECIMAL(12, 2);
                DECLARE run_percentage DECIMAL(7, 4);
                DECLARE run_correct INT;
                DECLARE run_incorrect INT;
                DECLARE run_unanswered INT;
                DECLARE run_manual_pending INT;
                DECLARE attempt_item_count INT DEFAULT 0;
                DECLARE score_count INT DEFAULT 0;
                DECLARE missing_item_count INT DEFAULT 0;
                DECLARE wrong_attempt_count INT DEFAULT 0;
                DECLARE manual_pending_scores INT DEFAULT 0;
                DECLARE sum_awarded DECIMAL(12, 2) DEFAULT 0.00;
                DECLARE sum_maximum DECIMAL(12, 2) DEFAULT 0.00;
                DECLARE expected_final DECIMAL(12, 2) DEFAULT 0.00;
                DECLARE expected_pct DECIMAL(7, 4) DEFAULT 0.0000;
                DECLARE cnt_correct INT DEFAULT 0;
                DECLARE cnt_incorrect INT DEFAULT 0;
                DECLARE cnt_unanswered INT DEFAULT 0;
                DECLARE cnt_manual_pending INT DEFAULT 0;
                DECLARE max_release_number INT DEFAULT 0;

                SELECT r.status, r.attempt_id, r.completed_at,
                       r.raw_points, r.final_points, r.maximum_points, r.percentage,
                       r.correct_count, r.incorrect_count, r.unanswered_count, r.manual_pending_count
                  INTO run_status, run_attempt_id, run_completed_at,
                       run_raw, run_final, run_maximum, run_percentage,
                       run_correct, run_incorrect, run_unanswered, run_manual_pending
                  FROM assessment_scoring_runs r
                 WHERE r.id = NEW.scoring_run_id
                 LIMIT 1;

                IF run_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release scoring_run not found';
                END IF;

                IF run_status <> 'completed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release requires completed scoring_run';
                END IF;

                IF run_attempt_id <> NEW.attempt_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release attempt mismatch';
                END IF;

                IF run_completed_at IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release requires scoring_run completed_at';
                END IF;

                SELECT COUNT(*) INTO attempt_item_count
                  FROM assessment_attempt_items ai
                 WHERE ai.attempt_id = NEW.attempt_id;

                SELECT COUNT(*) INTO score_count
                  FROM assessment_item_scores s
                 WHERE s.scoring_run_id = NEW.scoring_run_id;

                IF score_count <> attempt_item_count THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release item coverage mismatch';
                END IF;

                SELECT COUNT(*) INTO missing_item_count
                  FROM assessment_attempt_items ai
                 WHERE ai.attempt_id = NEW.attempt_id
                   AND NOT EXISTS (
                       SELECT 1 FROM assessment_item_scores s
                        WHERE s.scoring_run_id = NEW.scoring_run_id
                          AND s.attempt_item_id = ai.id
                   );

                IF missing_item_count <> 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release missing item_score coverage';
                END IF;

                SELECT COUNT(*) INTO wrong_attempt_count
                  FROM assessment_item_scores s
                 WHERE s.scoring_run_id = NEW.scoring_run_id
                   AND s.attempt_id <> NEW.attempt_id;

                IF wrong_attempt_count <> 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release item_score attempt mismatch';
                END IF;

                SELECT COUNT(*) INTO manual_pending_scores
                  FROM assessment_item_scores s
                 WHERE s.scoring_run_id = NEW.scoring_run_id
                   AND (s.outcome = 'manual_pending' OR s.manual_pending = 1);

                IF manual_pending_scores <> 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release has manual_pending item scores';
                END IF;

                SELECT COALESCE(SUM(s.awarded_points), 0),
                       COALESCE(SUM(s.maximum_points), 0),
                       SUM(CASE WHEN s.outcome = 'correct' THEN 1 ELSE 0 END),
                       SUM(CASE WHEN s.outcome IN ('incorrect', 'invalid') THEN 1 ELSE 0 END),
                       SUM(CASE WHEN s.outcome = 'unanswered' THEN 1 ELSE 0 END),
                       SUM(CASE WHEN s.outcome = 'manual_pending' OR s.manual_pending = 1 THEN 1 ELSE 0 END)
                  INTO sum_awarded, sum_maximum, cnt_correct, cnt_incorrect, cnt_unanswered, cnt_manual_pending
                  FROM assessment_item_scores s
                 WHERE s.scoring_run_id = NEW.scoring_run_id;

                IF run_maximum <= 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release scoring_run maximum_points invalid';
                END IF;

                IF CAST(run_raw AS DECIMAL(12, 2)) <> CAST(sum_awarded AS DECIMAL(12, 2))
                   OR CAST(run_maximum AS DECIMAL(12, 2)) <> CAST(sum_maximum AS DECIMAL(12, 2))
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release scoring_run points mismatch';
                END IF;

                SET expected_final = CAST(IF(run_raw < 0, 0, run_raw) AS DECIMAL(12, 2));
                IF CAST(run_final AS DECIMAL(12, 2)) <> expected_final THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release scoring_run final_points mismatch';
                END IF;

                SET expected_pct = CAST(
                    TRUNCATE(
                        (CAST(run_final AS DECIMAL(20, 8))
                         / CAST(run_maximum AS DECIMAL(20, 8))) * 100,
                        4
                    ) AS DECIMAL(7, 4)
                );
                IF CAST(run_percentage AS DECIMAL(7, 4)) <> expected_pct THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release scoring_run percentage mismatch';
                END IF;

                IF run_correct <> cnt_correct
                   OR run_incorrect <> cnt_incorrect
                   OR run_unanswered <> cnt_unanswered
                   OR run_manual_pending <> cnt_manual_pending
                   OR run_manual_pending <> 0
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release scoring_run counters mismatch';
                END IF;

                SELECT COALESCE(MAX(rr.release_number), 0) INTO max_release_number
                  FROM assessment_result_releases rr
                 WHERE rr.attempt_id = NEW.attempt_id;

                IF NEW.release_number <> (max_release_number + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release release_number must be sequential';
                END IF;
            END
            SQL);

        // D) BEFORE INSERT manual grade decisions
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_manual_grade_decisions_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_manual_grade_decisions_bi
            BEFORE INSERT ON assessment_manual_grade_decisions
            FOR EACH ROW
            BEGIN
                DECLARE run_status VARCHAR(32);
                DECLARE run_attempt_id BINARY(16);
                DECLARE item_outcome VARCHAR(32);
                DECLARE item_manual_pending TINYINT(1);
                DECLARE item_scoring_method VARCHAR(32);
                DECLARE item_maximum DECIMAL(10, 2);
                DECLARE item_exists INT DEFAULT 0;
                DECLARE max_decision_number INT DEFAULT 0;

                SELECT r.status, r.attempt_id
                  INTO run_status, run_attempt_id
                  FROM assessment_scoring_runs r
                 WHERE r.id = NEW.scoring_run_id
                 LIMIT 1;

                IF run_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision scoring_run not found';
                END IF;

                IF run_status <> 'pending_manual' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision requires pending_manual run';
                END IF;

                IF run_attempt_id <> NEW.attempt_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision attempt mismatch';
                END IF;

                SELECT COUNT(*),
                       MAX(s.outcome),
                       MAX(s.manual_pending),
                       MAX(s.scoring_method),
                       MAX(s.maximum_points)
                  INTO item_exists, item_outcome, item_manual_pending, item_scoring_method, item_maximum
                  FROM assessment_item_scores s
                 WHERE s.scoring_run_id = NEW.scoring_run_id
                   AND s.attempt_item_id = NEW.attempt_item_id;

                IF item_exists <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision item_score not found';
                END IF;

                IF item_outcome <> 'manual_pending'
                   OR item_manual_pending <> 1
                   OR item_scoring_method <> 'manual'
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision requires pending manual item';
                END IF;

                IF NEW.awarded_points < 0 OR NEW.awarded_points > item_maximum THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision awarded_points out of range';
                END IF;

                IF CAST(NEW.maximum_points AS DECIMAL(10, 2)) <> CAST(item_maximum AS DECIMAL(10, 2)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision maximum_points mismatch';
                END IF;

                IF NEW.evaluator_user_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision evaluator required';
                END IF;

                IF NEW.evaluated_at IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision evaluated_at required';
                END IF;

                IF NEW.reason_code IS NULL OR CHAR_LENGTH(TRIM(NEW.reason_code)) = 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision reason_code required';
                END IF;

                -- Sequential per (scoring_run_id, attempt_item_id): first=1 else MAX+1 exact.
                SELECT COALESCE(MAX(d.decision_number), 0) INTO max_decision_number
                  FROM assessment_manual_grade_decisions d
                 WHERE d.scoring_run_id = NEW.scoring_run_id
                   AND d.attempt_item_id = NEW.attempt_item_id;

                IF NEW.decision_number <> (max_decision_number + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision decision_number must be sequential';
                END IF;
            END
            SQL);

        // D) BEFORE UPDATE item scores: open-run + manual grade decision binding
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_item_scores_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_item_scores_bu_immutable
            BEFORE UPDATE ON assessment_item_scores
            FOR EACH ROW
            BEGIN
                DECLARE run_status VARCHAR(32);
                DECLARE decision_exists INT DEFAULT 0;
                DECLARE decision_awarded DECIMAL(10, 2);
                DECLARE decision_evaluator BINARY(16);
                DECLARE decision_evaluated_at DATETIME;
                DECLARE decision_reason VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

                IF OLD.scoring_run_id <> NEW.scoring_run_id
                   OR OLD.attempt_id <> NEW.attempt_id
                   OR OLD.attempt_item_id <> NEW.attempt_item_id
                   OR OLD.assessment_revision_id <> NEW.assessment_revision_id
                   OR OLD.question_id <> NEW.question_id
                   OR OLD.question_revision_id <> NEW.question_revision_id
                   OR OLD.maximum_points <> NEW.maximum_points
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score identity is immutable';
                END IF;

                SELECT r.status INTO run_status
                  FROM assessment_scoring_runs r
                 WHERE r.id = NEW.scoring_run_id
                 LIMIT 1;

                IF run_status IS NULL OR run_status NOT IN ('processing', 'pending_manual') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score update requires open scoring_run';
                END IF;

                IF OLD.outcome = 'manually_graded' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score already manually graded';
                END IF;

                IF OLD.outcome <> 'manual_pending'
                   AND (
                        NEW.outcome = 'manually_graded'
                        OR NEW.manual_pending = 1
                        OR NEW.scoring_method = 'manual'
                        OR NEW.evaluator_user_id IS NOT NULL
                        OR NEW.evaluated_at IS NOT NULL
                        OR NEW.reason_code IS NOT NULL
                   )
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score manual fields require decision path';
                END IF;

                IF NEW.outcome = 'manually_graded' THEN
                    IF OLD.outcome <> 'manual_pending' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score manual grade requires manual_pending';
                    END IF;

                    SELECT COUNT(*) INTO decision_exists
                      FROM assessment_manual_grade_decisions d
                     WHERE d.scoring_run_id = NEW.scoring_run_id
                       AND d.attempt_item_id = NEW.attempt_item_id;

                    IF decision_exists < 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score manual grade requires decision';
                    END IF;

                    SELECT d.awarded_points, d.evaluator_user_id, d.evaluated_at, d.reason_code
                      INTO decision_awarded, decision_evaluator, decision_evaluated_at, decision_reason
                      FROM assessment_manual_grade_decisions d
                     WHERE d.scoring_run_id = NEW.scoring_run_id
                       AND d.attempt_item_id = NEW.attempt_item_id
                     ORDER BY d.decision_number DESC
                     LIMIT 1;

                    IF CAST(NEW.awarded_points AS DECIMAL(10, 2)) <> CAST(decision_awarded AS DECIMAL(10, 2))
                       OR NOT (NEW.evaluator_user_id <=> decision_evaluator)
                       OR NOT (NEW.evaluated_at <=> decision_evaluated_at)
                       OR NOT (NEW.reason_code <=> decision_reason)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score must match latest decision';
                    END IF;

                    IF NEW.manual_pending <> 0 OR NEW.scoring_method <> 'manual' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score manual grade fields invalid';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260911120000 Stage 2.12 pre-merge hardening is irreversible; '
            .'downgrading would restore weaker scoring triggers.',
        );
    }
}
