<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.12: assessment scoring runs, item scores, manual decisions, result releases.
 *
 * Irreversible security migration — down() does not drop production scoring history.
 *
 * Does not modify Version20260910700000 or Version20260910800000.
 */
final class Version20260910900000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create assessment scoring runs, item scores, manual grade decisions, result releases, and active release guards';
    }

    public function up(Schema $schema): void
    {
        $tables = [
            'assessment_scoring_runs',
            'assessment_item_scores',
            'assessment_manual_grade_decisions',
            'assessment_result_releases',
            'assessment_result_active_release_guards',
        ];
        foreach ($tables as $tableName) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($tableName),
            );
            $this->abortIf(
                $exists > 0,
                \sprintf('Cannot create %s: table already exists.', $tableName),
            );
        }

        $attemptTable = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'assessment_attempts'",
        );
        $this->abortIf(
            0 === $attemptTable,
            'Version20260910900000 requires assessment_attempts from Version20260910700000/10800000.',
        );

        $hasAaiQuestionRevisionUnique = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'assessment_attempt_items'
              AND index_name = 'uniq_aai_id_question_revision'
            SQL);
        if (0 === $hasAaiQuestionRevisionUnique) {
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_aai_id_question_revision ON assessment_attempt_items (id, question_id, question_revision_id)',
            );
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_scoring_runs (
                id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                institution_id BINARY(16) NOT NULL,
                delivery_id BINARY(16) NOT NULL,
                recipient_id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                assessment_id BINARY(16) NOT NULL,
                assessment_publication_id BINARY(16) NOT NULL,
                assessment_revision_id BINARY(16) NOT NULL,
                publication_number INT NOT NULL,
                scoring_policy_id VARCHAR(64) NOT NULL,
                scoring_version INT NOT NULL,
                run_number INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                raw_points NUMERIC(12, 2) NOT NULL,
                final_points NUMERIC(12, 2) NOT NULL,
                maximum_points NUMERIC(12, 2) NOT NULL,
                percentage NUMERIC(7, 4) NOT NULL,
                correct_count INT NOT NULL,
                incorrect_count INT NOT NULL,
                unanswered_count INT NOT NULL,
                manual_pending_count INT NOT NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                reason_code VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_asr_attempt_run (attempt_id, run_number),
                UNIQUE INDEX uniq_asr_id_attempt (id, attempt_id),
                UNIQUE INDEX uniq_asr_id_institution (id, institution_id),
                UNIQUE INDEX uniq_asr_id_delivery (id, delivery_id),
                UNIQUE INDEX uniq_asr_id_recipient (id, recipient_id),
                UNIQUE INDEX uniq_asr_id_user (id, user_id),
                UNIQUE INDEX uniq_asr_id_assessment (id, assessment_id),
                UNIQUE INDEX uniq_asr_id_publication (id, assessment_publication_id),
                UNIQUE INDEX uniq_asr_id_revision (id, assessment_revision_id),
                UNIQUE INDEX uniq_asr_id_attempt_revision (id, attempt_id, assessment_revision_id),
                INDEX idx_asr_attempt_status (attempt_id, status),
                INDEX idx_asr_institution_status (institution_id, status),
                PRIMARY KEY (id),
                CONSTRAINT chk_asr_status CHECK (status IN ('processing', 'pending_manual', 'completed', 'failed')),
                CONSTRAINT chk_asr_run_number CHECK (run_number >= 1),
                CONSTRAINT chk_asr_scoring_version CHECK (scoring_version >= 1),
                CONSTRAINT chk_asr_publication_number CHECK (publication_number >= 1),
                CONSTRAINT chk_asr_counters CHECK (
                    correct_count >= 0
                    AND incorrect_count >= 0
                    AND unanswered_count >= 0
                    AND manual_pending_count >= 0
                ),
                CONSTRAINT chk_asr_status_timestamps CHECK (
                    (
                        status IN ('processing', 'pending_manual', 'failed')
                        AND completed_at IS NULL
                    )
                    OR (
                        status = 'completed'
                        AND completed_at IS NOT NULL
                    )
                ),
                CONSTRAINT chk_asr_points CHECK (
                    status IN ('processing', 'failed')
                    OR (
                        maximum_points > 0
                        AND final_points >= 0
                        AND final_points <= maximum_points
                        AND percentage >= 0
                        AND percentage <= 100
                    )
                ),
                CONSTRAINT chk_asr_pending_manual_count CHECK (
                    (status = 'pending_manual' AND manual_pending_count >= 1)
                    OR (status = 'completed' AND manual_pending_count = 0)
                    OR (status IN ('processing', 'failed'))
                ),
                CONSTRAINT chk_asr_scoring_policy_id CHECK (
                    scoring_policy_id REGEXP '^[a-z][a-z0-9_]{0,63}$'
                ),
                CONSTRAINT chk_asr_reason_code CHECK (
                    reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_item_scores (
                id BINARY(16) NOT NULL,
                scoring_run_id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                attempt_item_id BINARY(16) NOT NULL,
                assessment_revision_id BINARY(16) NOT NULL,
                question_id BINARY(16) NOT NULL,
                question_revision_id BINARY(16) NOT NULL,
                scoring_method VARCHAR(32) NOT NULL,
                outcome VARCHAR(32) NOT NULL,
                maximum_points NUMERIC(10, 2) NOT NULL,
                awarded_points NUMERIC(10, 2) NOT NULL,
                penalty_points_applied NUMERIC(10, 2) NOT NULL,
                manual_pending TINYINT(1) NOT NULL,
                evaluator_user_id BINARY(16) DEFAULT NULL,
                evaluated_at DATETIME DEFAULT NULL,
                reason_code VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_ais_run_item (scoring_run_id, attempt_item_id),
                UNIQUE INDEX uniq_ais_id_run (id, scoring_run_id),
                UNIQUE INDEX uniq_ais_id_run_item (id, scoring_run_id, attempt_item_id),
                INDEX idx_ais_attempt (attempt_id),
                INDEX idx_ais_run_outcome (scoring_run_id, outcome),
                PRIMARY KEY (id),
                CONSTRAINT chk_ais_scoring_method CHECK (scoring_method IN ('automatic', 'manual')),
                CONSTRAINT chk_ais_outcome CHECK (
                    outcome IN ('correct', 'incorrect', 'unanswered', 'manual_pending', 'manually_graded', 'invalid')
                ),
                CONSTRAINT chk_ais_maximum_points CHECK (maximum_points > 0),
                CONSTRAINT chk_ais_penalty CHECK (penalty_points_applied >= 0),
                CONSTRAINT chk_ais_awarded CHECK (
                    awarded_points >= -penalty_points_applied
                    AND awarded_points <= maximum_points
                ),
                CONSTRAINT chk_ais_manual_pending CHECK (
                    (manual_pending = 1 AND outcome = 'manual_pending')
                    OR (manual_pending = 0 AND outcome <> 'manual_pending')
                ),
                CONSTRAINT chk_ais_manual_fields CHECK (
                    (
                        outcome = 'manually_graded'
                        AND scoring_method = 'manual'
                        AND evaluator_user_id IS NOT NULL
                        AND evaluated_at IS NOT NULL
                        AND reason_code IS NOT NULL
                    )
                    OR (
                        outcome <> 'manually_graded'
                    )
                ),
                CONSTRAINT chk_ais_reason_code CHECK (
                    reason_code IS NULL
                    OR reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_manual_grade_decisions (
                id BINARY(16) NOT NULL,
                scoring_run_id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                attempt_item_id BINARY(16) NOT NULL,
                decision_number INT NOT NULL,
                awarded_points NUMERIC(10, 2) NOT NULL,
                maximum_points NUMERIC(10, 2) NOT NULL,
                outcome VARCHAR(32) NOT NULL,
                evaluator_user_id BINARY(16) NOT NULL,
                evaluated_at DATETIME NOT NULL,
                reason_code VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_amgd_item_decision (attempt_item_id, decision_number),
                UNIQUE INDEX uniq_amgd_run_item_decision (scoring_run_id, attempt_item_id, decision_number),
                INDEX idx_amgd_attempt (attempt_id),
                INDEX idx_amgd_run (scoring_run_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_amgd_decision_number CHECK (decision_number >= 1),
                CONSTRAINT chk_amgd_outcome CHECK (outcome = 'manually_graded'),
                CONSTRAINT chk_amgd_maximum_points CHECK (maximum_points > 0),
                CONSTRAINT chk_amgd_awarded CHECK (
                    awarded_points >= 0
                    AND awarded_points <= maximum_points
                ),
                CONSTRAINT chk_amgd_reason_code CHECK (
                    reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_result_releases (
                id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                scoring_run_id BINARY(16) NOT NULL,
                release_number INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                released_by_id BINARY(16) DEFAULT NULL,
                released_at DATETIME DEFAULT NULL,
                withdrawn_by_id BINARY(16) DEFAULT NULL,
                withdrawn_at DATETIME DEFAULT NULL,
                reason_code VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_arr_attempt_release (attempt_id, release_number),
                UNIQUE INDEX uniq_arr_id_attempt (id, attempt_id),
                UNIQUE INDEX uniq_arr_id_run (id, scoring_run_id),
                UNIQUE INDEX uniq_arr_id_attempt_run (id, attempt_id, scoring_run_id),
                INDEX idx_arr_attempt_status (attempt_id, status),
                INDEX idx_arr_run (scoring_run_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_arr_status CHECK (status IN ('draft', 'released', 'superseded', 'withdrawn')),
                CONSTRAINT chk_arr_release_number CHECK (release_number >= 1),
                CONSTRAINT chk_arr_lifecycle CHECK (
                    (
                        status = 'draft'
                        AND released_at IS NULL AND released_by_id IS NULL
                        AND withdrawn_at IS NULL AND withdrawn_by_id IS NULL
                    )
                    OR (
                        status = 'released'
                        AND released_at IS NOT NULL AND released_by_id IS NOT NULL
                        AND withdrawn_at IS NULL AND withdrawn_by_id IS NULL
                    )
                    OR (
                        status = 'superseded'
                        AND released_at IS NOT NULL AND released_by_id IS NOT NULL
                        AND withdrawn_at IS NULL AND withdrawn_by_id IS NULL
                    )
                    OR (
                        status = 'withdrawn'
                        AND released_at IS NOT NULL AND released_by_id IS NOT NULL
                        AND withdrawn_at IS NOT NULL AND withdrawn_by_id IS NOT NULL
                    )
                ),
                CONSTRAINT chk_arr_reason_code CHECK (
                    reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_result_active_release_guards (
                attempt_id BINARY(16) NOT NULL,
                release_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_ararg_release (release_id),
                PRIMARY KEY (attempt_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        // Simple FKs
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_DELIVERY FOREIGN KEY (delivery_id) REFERENCES assessment_deliveries (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES assessment_delivery_recipients (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_PUBLICATION FOREIGN KEY (assessment_publication_id) REFERENCES assessment_publications (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_REVISION FOREIGN KEY (assessment_revision_id) REFERENCES assessment_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_INSTITUTION FOREIGN KEY (attempt_id, institution_id) REFERENCES assessment_attempts (id, institution_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_DELIVERY FOREIGN KEY (attempt_id, delivery_id) REFERENCES assessment_attempts (id, delivery_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_RECIPIENT FOREIGN KEY (attempt_id, recipient_id) REFERENCES assessment_attempts (id, recipient_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_USER FOREIGN KEY (attempt_id, user_id) REFERENCES assessment_attempts (id, user_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_ASSESSMENT FOREIGN KEY (attempt_id, assessment_id) REFERENCES assessment_attempts (id, assessment_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_PUBLICATION FOREIGN KEY (attempt_id, assessment_publication_id) REFERENCES assessment_attempts (id, assessment_publication_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_REVISION FOREIGN KEY (attempt_id, assessment_revision_id) REFERENCES assessment_attempts (id, assessment_revision_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_ATTEMPT_DELIVERY_RECIPIENT FOREIGN KEY (attempt_id, delivery_id, recipient_id) REFERENCES assessment_attempts (id, delivery_id, recipient_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_PUBLICATION_ASSESSMENT_NUMBER FOREIGN KEY (assessment_publication_id, assessment_id, publication_number) REFERENCES assessment_publications (id, assessment_id, publication_number) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_scoring_runs ADD CONSTRAINT FK_ASR_PUBLICATION_REVISION FOREIGN KEY (assessment_publication_id, assessment_revision_id) REFERENCES assessment_publications (id, assessment_revision_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_RUN FOREIGN KEY (scoring_run_id) REFERENCES assessment_scoring_runs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_ITEM FOREIGN KEY (attempt_item_id) REFERENCES assessment_attempt_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_REVISION FOREIGN KEY (assessment_revision_id) REFERENCES assessment_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_QUESTION FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_QUESTION_REVISION FOREIGN KEY (question_revision_id) REFERENCES question_revisions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_EVALUATOR FOREIGN KEY (evaluator_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_RUN_ATTEMPT FOREIGN KEY (scoring_run_id, attempt_id) REFERENCES assessment_scoring_runs (id, attempt_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_RUN_ATTEMPT_REVISION FOREIGN KEY (scoring_run_id, attempt_id, assessment_revision_id) REFERENCES assessment_scoring_runs (id, attempt_id, assessment_revision_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_ITEM_ATTEMPT FOREIGN KEY (attempt_item_id, attempt_id) REFERENCES assessment_attempt_items (id, attempt_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_ITEM_ATTEMPT_REVISION FOREIGN KEY (attempt_item_id, attempt_id, assessment_revision_id) REFERENCES assessment_attempt_items (id, attempt_id, assessment_revision_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_item_scores ADD CONSTRAINT FK_AIS_ITEM_QUESTION_REVISION FOREIGN KEY (attempt_item_id, question_id, question_revision_id) REFERENCES assessment_attempt_items (id, question_id, question_revision_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_manual_grade_decisions ADD CONSTRAINT FK_AMGD_RUN FOREIGN KEY (scoring_run_id) REFERENCES assessment_scoring_runs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions ADD CONSTRAINT FK_AMGD_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions ADD CONSTRAINT FK_AMGD_ITEM FOREIGN KEY (attempt_item_id) REFERENCES assessment_attempt_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions ADD CONSTRAINT FK_AMGD_EVALUATOR FOREIGN KEY (evaluator_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions ADD CONSTRAINT FK_AMGD_RUN_ATTEMPT FOREIGN KEY (scoring_run_id, attempt_id) REFERENCES assessment_scoring_runs (id, attempt_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions ADD CONSTRAINT FK_AMGD_ITEM_ATTEMPT FOREIGN KEY (attempt_item_id, attempt_id) REFERENCES assessment_attempt_items (id, attempt_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE assessment_result_releases ADD CONSTRAINT FK_ARR_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_result_releases ADD CONSTRAINT FK_ARR_RUN FOREIGN KEY (scoring_run_id) REFERENCES assessment_scoring_runs (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_result_releases ADD CONSTRAINT FK_ARR_RELEASED_BY FOREIGN KEY (released_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_result_releases ADD CONSTRAINT FK_ARR_WITHDRAWN_BY FOREIGN KEY (withdrawn_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_result_releases ADD CONSTRAINT FK_ARR_RUN_ATTEMPT FOREIGN KEY (scoring_run_id, attempt_id) REFERENCES assessment_scoring_runs (id, attempt_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE assessment_result_active_release_guards ADD CONSTRAINT FK_ARARG_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES assessment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_result_active_release_guards ADD CONSTRAINT FK_ARARG_RELEASE FOREIGN KEY (release_id) REFERENCES assessment_result_releases (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_result_active_release_guards ADD CONSTRAINT FK_ARARG_RELEASE_ATTEMPT FOREIGN KEY (release_id, attempt_id) REFERENCES assessment_result_releases (id, attempt_id) ON DELETE CASCADE');

        // Align MariaDB auto-named FK indexes with Doctrine expected IDX_* names.
        $this->addSql('ALTER TABLE assessment_item_scores RENAME INDEX FK_AIS_REVISION TO IDX_57D68FA6CB85B1B8');
        $this->addSql('ALTER TABLE assessment_item_scores RENAME INDEX FK_AIS_QUESTION TO IDX_57D68FA61E27F6BF');
        $this->addSql('ALTER TABLE assessment_item_scores RENAME INDEX FK_AIS_QUESTION_REVISION TO IDX_57D68FA6FEFEA302');
        $this->addSql('ALTER TABLE assessment_item_scores RENAME INDEX FK_AIS_EVALUATOR TO IDX_57D68FA68DD48763');
        $this->addSql('ALTER TABLE assessment_item_scores RENAME INDEX FK_AIS_RUN_ATTEMPT_REVISION TO IDX_57D68FA68BC55B95B191BE6BCB85B1B8');
        $this->addSql('ALTER TABLE assessment_item_scores RENAME INDEX FK_AIS_ITEM_ATTEMPT_REVISION TO IDX_57D68FA6D62A0C8CB191BE6BCB85B1B8');
        $this->addSql('ALTER TABLE assessment_item_scores RENAME INDEX FK_AIS_ITEM_QUESTION_REVISION TO IDX_57D68FA6D62A0C8C1E27F6BFFEFEA302');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions RENAME INDEX FK_AMGD_EVALUATOR TO IDX_99C1DB7F8DD48763');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions RENAME INDEX FK_AMGD_RUN_ATTEMPT TO IDX_99C1DB7F8BC55B95B191BE6B');
        $this->addSql('ALTER TABLE assessment_manual_grade_decisions RENAME INDEX FK_AMGD_ITEM_ATTEMPT TO IDX_99C1DB7FD62A0C8CB191BE6B');
        $this->addSql('ALTER TABLE assessment_result_active_release_guards RENAME INDEX FK_ARARG_RELEASE_ATTEMPT TO IDX_445C15EDB12A727DB191BE6B');
        $this->addSql('ALTER TABLE assessment_result_releases RENAME INDEX FK_ARR_RELEASED_BY TO IDX_EC0F2CE1445947DD');
        $this->addSql('ALTER TABLE assessment_result_releases RENAME INDEX FK_ARR_WITHDRAWN_BY TO IDX_EC0F2CE189BE4D2E');
        $this->addSql('ALTER TABLE assessment_result_releases RENAME INDEX FK_ARR_RUN_ATTEMPT TO IDX_EC0F2CE18BC55B95B191BE6B');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_DELIVERY TO IDX_BD0444ED12136921');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_RECIPIENT TO IDX_BD0444EDE92F8F78');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_USER TO IDX_BD0444EDA76ED395');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ASSESSMENT TO IDX_BD0444EDDD3DD5F1');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_REVISION TO IDX_BD0444EDCB85B1B8');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_CREATED_BY TO IDX_BD0444EDB03A8386');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ATTEMPT_INSTITUTION TO IDX_BD0444EDB191BE6B10405986');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ATTEMPT_RECIPIENT TO IDX_BD0444EDB191BE6BE92F8F78');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ATTEMPT_USER TO IDX_BD0444EDB191BE6BA76ED395');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ATTEMPT_ASSESSMENT TO IDX_BD0444EDB191BE6BDD3DD5F1');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ATTEMPT_PUBLICATION TO IDX_BD0444EDB191BE6BA77821CA');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ATTEMPT_REVISION TO IDX_BD0444EDB191BE6BCB85B1B8');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_ATTEMPT_DELIVERY_RECIPIENT TO IDX_BD0444EDB191BE6B12136921E92F8F78');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_PUBLICATION_ASSESSMENT_NUMBER TO IDX_BD0444EDA77821CADD3DD5F1727B0E19');
        $this->addSql('ALTER TABLE assessment_scoring_runs RENAME INDEX FK_ASR_PUBLICATION_REVISION TO IDX_BD0444EDA77821CACB85B1B8');

        // BEFORE INSERT scoring run: attempt must be submitted or expired
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_scoring_runs_bi_attempt');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_scoring_runs_bi_attempt
            BEFORE INSERT ON assessment_scoring_runs
            FOR EACH ROW
            BEGIN
                DECLARE attempt_status VARCHAR(32);

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
            END
            SQL);

        // Completed scoring run identity + aggregate immutability
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_scoring_runs_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_scoring_runs_bu_immutable
            BEFORE UPDATE ON assessment_scoring_runs
            FOR EACH ROW
            BEGIN
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
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_scoring_runs_bd_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_scoring_runs_bd_immutable
            BEFORE DELETE ON assessment_scoring_runs
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'completed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'completed scoring_run cannot be deleted';
                END IF;
            END
            SQL);

        // Item scores immutable when parent run completed
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_item_scores_bi_run');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_item_scores_bi_run
            BEFORE INSERT ON assessment_item_scores
            FOR EACH ROW
            BEGIN
                DECLARE run_status VARCHAR(32);

                SELECT r.status INTO run_status
                  FROM assessment_scoring_runs r
                 WHERE r.id = NEW.scoring_run_id
                 LIMIT 1;

                IF run_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score scoring_run not found';
                END IF;

                IF run_status NOT IN ('processing', 'pending_manual') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score insert requires open scoring_run';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_item_scores_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_item_scores_bu_immutable
            BEFORE UPDATE ON assessment_item_scores
            FOR EACH ROW
            BEGIN
                DECLARE run_status VARCHAR(32);

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
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_item_scores_bd_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_item_scores_bd_immutable
            BEFORE DELETE ON assessment_item_scores
            FOR EACH ROW
            BEGIN
                DECLARE run_status VARCHAR(32);

                SELECT r.status INTO run_status
                  FROM assessment_scoring_runs r
                 WHERE r.id = OLD.scoring_run_id
                 LIMIT 1;

                IF run_status = 'completed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_score cannot be deleted when scoring_run completed';
                END IF;
            END
            SQL);

        // Manual decisions append-only
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_manual_grade_decisions_bu');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_manual_grade_decisions_bu
            BEFORE UPDATE ON assessment_manual_grade_decisions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision is append-only';
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_manual_grade_decisions_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_manual_grade_decisions_bd
            BEFORE DELETE ON assessment_manual_grade_decisions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'manual_grade_decision is append-only';
            END
            SQL);

        // Result release: completed run only + state machine + guard sync
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_releases_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_releases_bi
            BEFORE INSERT ON assessment_result_releases
            FOR EACH ROW
            BEGIN
                DECLARE run_status VARCHAR(32);
                DECLARE run_attempt_id BINARY(16);

                SELECT r.status, r.attempt_id
                  INTO run_status, run_attempt_id
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
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_releases_bu');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_releases_bu
            BEFORE UPDATE ON assessment_result_releases
            FOR EACH ROW
            BEGIN
                IF OLD.attempt_id <> NEW.attempt_id
                   OR OLD.scoring_run_id <> NEW.scoring_run_id
                   OR OLD.release_number <> NEW.release_number
                   OR OLD.reason_code <> NEW.reason_code
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release identity is immutable';
                END IF;

                IF NOT (
                    (OLD.status = 'draft' AND NEW.status IN ('draft', 'released'))
                    OR (OLD.status = 'released' AND NEW.status IN ('released', 'superseded', 'withdrawn'))
                    OR (OLD.status = 'superseded' AND NEW.status = 'superseded')
                    OR (OLD.status = 'withdrawn' AND NEW.status = 'withdrawn')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release status transition is not allowed';
                END IF;

                IF OLD.status = NEW.status THEN
                    IF NOT (OLD.released_at <=> NEW.released_at)
                       OR NOT (OLD.released_by_id <=> NEW.released_by_id)
                       OR NOT (OLD.withdrawn_at <=> NEW.withdrawn_at)
                       OR NOT (OLD.withdrawn_by_id <=> NEW.withdrawn_by_id)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release lifecycle fields immutable when status unchanged';
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_releases_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_releases_bd
            BEFORE DELETE ON assessment_result_releases
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result_release cannot be deleted';
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_releases_ai_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_releases_ai_guard
            AFTER INSERT ON assessment_result_releases
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'released' THEN
                    INSERT INTO assessment_result_active_release_guards (attempt_id, release_id)
                    VALUES (NEW.attempt_id, NEW.id);
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_releases_au_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_releases_au_guard
            AFTER UPDATE ON assessment_result_releases
            FOR EACH ROW
            BEGIN
                DECLARE guard_exists INT DEFAULT 0;

                IF OLD.status = 'released' AND NEW.status <> 'released' THEN
                    DELETE FROM assessment_result_active_release_guards
                     WHERE release_id = NEW.id;
                END IF;

                IF NEW.status = 'released' THEN
                    SELECT COUNT(*) INTO guard_exists
                      FROM assessment_result_active_release_guards g
                     WHERE g.attempt_id = NEW.attempt_id;

                    IF guard_exists = 0 THEN
                        INSERT INTO assessment_result_active_release_guards (attempt_id, release_id)
                        VALUES (NEW.attempt_id, NEW.id);
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_active_release_guards_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_active_release_guards_bi
            BEFORE INSERT ON assessment_result_active_release_guards
            FOR EACH ROW
            BEGIN
                DECLARE release_status VARCHAR(32);
                DECLARE release_attempt_id BINARY(16);

                SELECT r.status, r.attempt_id
                  INTO release_status, release_attempt_id
                  FROM assessment_result_releases r
                 WHERE r.id = NEW.release_id
                 LIMIT 1;

                IF release_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_release_guard release not found';
                END IF;

                IF release_status <> 'released' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_release_guard requires released status';
                END IF;

                IF release_attempt_id <> NEW.attempt_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_release_guard attempt mismatch';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_active_release_guards_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_active_release_guards_bd
            BEFORE DELETE ON assessment_result_active_release_guards
            FOR EACH ROW
            BEGIN
                DECLARE release_status VARCHAR(32);

                SELECT r.status
                  INTO release_status
                  FROM assessment_result_releases r
                 WHERE r.id = OLD.release_id
                 LIMIT 1;

                IF release_status = 'released' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot detach active release guard';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Stage 2.12 assessment scoring migration is irreversible.',
        );
    }
}
