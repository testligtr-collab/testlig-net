<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.19 — dead-letter requeue lifecycle + payment reconciliation tables.
 */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow dead_letter→retry_pending requeue; add payment reconciliation run/item tables.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->createSchemaManager()->tablesExist(['payment_webhook_inbox_events']),
            'payment_webhook_inbox_events must exist before Stage 2.19.',
        );
        $this->abortIf(
            !$this->connection->createSchemaManager()->tablesExist(['payment_attempts']),
            'payment_attempts must exist before Stage 2.19.',
        );
        $this->abortIf(
            $this->connection->createSchemaManager()->tablesExist(['payment_reconciliation_runs']),
            'payment_reconciliation_runs already exists.',
        );

        $this->addSql('DROP TRIGGER IF EXISTS trg_pwie_bu_lifecycle');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pwie_bu_lifecycle
            BEFORE UPDATE ON payment_webhook_inbox_events
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.provider_code <> NEW.provider_code
                   OR OLD.environment <> NEW.environment
                   OR OLD.provider_event_reference <> NEW.provider_event_reference
                   OR OLD.event_type <> NEW.event_type
                   OR OLD.payload_hash <> NEW.payload_hash
                   OR OLD.signature_fingerprint <> NEW.signature_fingerprint
                   OR OLD.received_at <> NEW.received_at
                   OR OLD.provider_occurred_at <> NEW.provider_occurred_at
                   OR OLD.schema_version <> NEW.schema_version
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_webhook_inbox_events identity fields are immutable';
                END IF;
                IF NEW.attempt_count < OLD.attempt_count THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_webhook_inbox_events attempt_count is monotonic';
                END IF;
                IF OLD.processing_status IN ('processed', 'rejected')
                   AND NEW.processing_status <> OLD.processing_status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_webhook_inbox_events terminal status cannot change';
                END IF;
                IF OLD.processing_status = 'dead_letter'
                   AND NEW.processing_status NOT IN ('dead_letter', 'retry_pending') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid payment_webhook_inbox_events dead_letter transition';
                END IF;
                IF OLD.processing_status = 'received'
                   AND NEW.processing_status NOT IN ('received', 'processing', 'rejected') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid payment_webhook_inbox_events status transition';
                END IF;
                IF OLD.processing_status = 'processing'
                   AND NEW.processing_status NOT IN (
                        'processing', 'processed', 'rejected', 'retry_pending', 'dead_letter'
                   ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid payment_webhook_inbox_events status transition';
                END IF;
                IF OLD.processing_status = 'retry_pending'
                   AND NEW.processing_status NOT IN ('retry_pending', 'processing', 'rejected', 'dead_letter') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid payment_webhook_inbox_events status transition';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE payment_reconciliation_runs (
                id BINARY(16) NOT NULL,
                provider_code VARCHAR(64) NOT NULL,
                environment VARCHAR(16) NOT NULL,
                mode VARCHAR(16) NOT NULL,
                status VARCHAR(32) NOT NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                created_by_id BINARY(16) DEFAULT NULL,
                reason_code VARCHAR(64) NOT NULL,
                checked_count INT NOT NULL DEFAULT 0,
                matched_count INT NOT NULL DEFAULT 0,
                discrepancy_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                schema_version INT NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_prr_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_prr_mode CHECK (mode IN ('manual', 'scheduled')),
                CONSTRAINT chk_prr_status CHECK (status IN (
                    'running', 'completed', 'completed_with_discrepancies', 'failed'
                )),
                CONSTRAINT chk_prr_environment CHECK (environment IN ('sandbox', 'production')),
                CONSTRAINT chk_prr_counters_nonneg CHECK (
                    checked_count >= 0
                    AND matched_count >= 0
                    AND discrepancy_count >= 0
                    AND failed_count >= 0
                ),
                CONSTRAINT chk_prr_counters_sum CHECK (
                    checked_count = matched_count + discrepancy_count + failed_count
                ),
                CONSTRAINT chk_prr_completion_null_pair CHECK (
                    (status = 'running' AND completed_at IS NULL
                        AND checked_count = 0 AND matched_count = 0
                        AND discrepancy_count = 0 AND failed_count = 0)
                    OR (status IN ('completed', 'completed_with_discrepancies', 'failed')
                        AND completed_at IS NOT NULL)
                ),
                CONSTRAINT chk_prr_reason_code CHECK (
                    reason_code REGEXP BINARY '^[a-z][a-z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_prr_schema_version CHECK (schema_version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('CREATE INDEX idx_prr_provider_env_started ON payment_reconciliation_runs (provider_code, environment, started_at)');
        $this->addSql('CREATE INDEX idx_prr_status_started ON payment_reconciliation_runs (status, started_at)');
        $this->addSql('ALTER TABLE payment_reconciliation_runs RENAME INDEX fk_prr_created_by TO IDX_82A7F39B03A8386');

        $this->addSql(<<<'SQL'
            CREATE TABLE payment_reconciliation_items (
                id BINARY(16) NOT NULL,
                run_id BINARY(16) NOT NULL,
                payment_attempt_id BINARY(16) NOT NULL,
                expected_state VARCHAR(32) NOT NULL,
                provider_state VARCHAR(32) DEFAULT NULL,
                outcome VARCHAR(32) NOT NULL,
                action VARCHAR(32) NOT NULL,
                safe_snapshot_hash VARCHAR(64) DEFAULT NULL,
                checked_at DATETIME NOT NULL,
                reason_code VARCHAR(64) DEFAULT NULL,
                schema_version INT NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT uniq_pri_run_attempt UNIQUE (run_id, payment_attempt_id),
                CONSTRAINT fk_pri_run FOREIGN KEY (run_id) REFERENCES payment_reconciliation_runs (id) ON DELETE CASCADE,
                CONSTRAINT fk_pri_attempt FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts (id) ON DELETE CASCADE,
                CONSTRAINT chk_pri_expected_state CHECK (expected_state IN (
                    'initiated', 'authorized', 'captured', 'failed', 'cancelled'
                )),
                CONSTRAINT chk_pri_provider_state CHECK (
                    provider_state IS NULL OR provider_state IN (
                        'initiated', 'authorized', 'captured', 'failed', 'cancelled'
                    )
                ),
                CONSTRAINT chk_pri_outcome CHECK (outcome IN (
                    'matched', 'local_behind', 'provider_behind', 'amount_mismatch',
                    'currency_mismatch', 'reference_mismatch', 'missing_at_provider',
                    'unsupported', 'failed'
                )),
                CONSTRAINT chk_pri_action CHECK (action IN (
                    'none', 'webhook_requeued', 'manual_review_required'
                )),
                CONSTRAINT chk_pri_snapshot_hash CHECK (
                    safe_snapshot_hash IS NULL OR safe_snapshot_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_pri_reason_code CHECK (
                    reason_code IS NULL OR reason_code REGEXP BINARY '^[a-z][a-z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_pri_schema_version CHECK (schema_version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('CREATE INDEX idx_pri_outcome ON payment_reconciliation_items (outcome)');
        $this->addSql('CREATE INDEX idx_pri_attempt ON payment_reconciliation_items (payment_attempt_id)');
        $this->addSql('CREATE INDEX idx_pri_run_outcome ON payment_reconciliation_items (run_id, outcome)');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_prr_bu_finalize
            BEFORE UPDATE ON payment_reconciliation_runs
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.provider_code <> NEW.provider_code
                   OR OLD.environment <> NEW.environment
                   OR OLD.mode <> NEW.mode
                   OR OLD.started_at <> NEW.started_at
                   OR OLD.reason_code <> NEW.reason_code
                   OR OLD.schema_version <> NEW.schema_version
                   OR IFNULL(OLD.created_by_id, 0x00) <> IFNULL(NEW.created_by_id, 0x00)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_reconciliation_runs identity fields are immutable';
                END IF;
                IF OLD.status <> 'running' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_reconciliation_runs terminal rows are immutable';
                END IF;
                IF NEW.status NOT IN ('completed', 'completed_with_discrepancies', 'failed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_reconciliation_runs may only finalize to a terminal status';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_prr_bd_deny
            BEFORE DELETE ON payment_reconciliation_runs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_reconciliation_runs cannot be deleted';
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pri_bu_deny
            BEFORE UPDATE ON payment_reconciliation_items
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_reconciliation_items are append-only';
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pri_bd_deny
            BEFORE DELETE ON payment_reconciliation_items
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_reconciliation_items cannot be deleted';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Version20260915120000 is irreversible to preserve reconciliation history.');
    }
}
