<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.18 recovery — webhook inbox lease, retry_pending, dead_letter.
 */
final class Version20260914130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment webhook inbox claim/lease/retry/dead-letter recovery columns and lifecycle.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->createSchemaManager()->tablesExist(['payment_webhook_inbox_events']),
            'payment_webhook_inbox_events must exist before recovery hardening.',
        );

        $failedCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM payment_webhook_inbox_events WHERE processing_status = 'failed'",
        );
        $this->abortIf(
            $failedCount > 0,
            'Cannot harden webhook inbox while failed rows exist; migrate or clear them first.',
        );

        $this->addSql('DROP TRIGGER IF EXISTS trg_pwie_bu_lifecycle');

        $this->addSql('ALTER TABLE payment_webhook_inbox_events DROP CONSTRAINT chk_pwie_status');
        $this->addSql('ALTER TABLE payment_webhook_inbox_events DROP CONSTRAINT chk_pwie_processed_null_pair');

        $this->addSql(<<<'SQL'
            ALTER TABLE payment_webhook_inbox_events
                ADD processing_started_at DATETIME DEFAULT NULL,
                ADD next_retry_at DATETIME DEFAULT NULL,
                ADD attempt_count INT NOT NULL DEFAULT 0,
                ADD last_failure_reason_code VARCHAR(64) DEFAULT NULL,
                ADD claim_token BINARY(16) DEFAULT NULL,
                ADD lease_expires_at DATETIME DEFAULT NULL,
                ADD closed_at DATETIME DEFAULT NULL,
                ADD CONSTRAINT chk_pwie_attempt_count CHECK (attempt_count >= 0),
                ADD CONSTRAINT chk_pwie_status CHECK (processing_status IN (
                    'received', 'processing', 'retry_pending', 'processed', 'rejected', 'dead_letter'
                )),
                ADD CONSTRAINT chk_pwie_processed_null_pair CHECK (
                    (processing_status IN ('received', 'processing', 'retry_pending')
                        AND processed_at IS NULL
                        AND closed_at IS NULL
                        AND failure_reason_code IS NULL)
                    OR (processing_status = 'processed'
                        AND processed_at IS NOT NULL
                        AND closed_at IS NULL
                        AND failure_reason_code IS NULL
                        AND claim_token IS NULL
                        AND lease_expires_at IS NULL
                        AND next_retry_at IS NULL)
                    OR (processing_status IN ('rejected', 'dead_letter')
                        AND processed_at IS NULL
                        AND closed_at IS NOT NULL
                        AND failure_reason_code IS NOT NULL
                        AND claim_token IS NULL
                        AND lease_expires_at IS NULL
                        AND next_retry_at IS NULL)
                ),
                ADD CONSTRAINT chk_pwie_lease_null_pair CHECK (
                    (claim_token IS NULL AND lease_expires_at IS NULL)
                    OR (claim_token IS NOT NULL AND lease_expires_at IS NOT NULL AND processing_status = 'processing')
                ),
                ADD CONSTRAINT chk_pwie_retry_pending_pair CHECK (
                    (processing_status <> 'retry_pending' AND next_retry_at IS NULL)
                    OR (processing_status = 'retry_pending'
                        AND next_retry_at IS NOT NULL
                        AND last_failure_reason_code IS NOT NULL
                        AND attempt_count >= 1)
                ),
                ADD CONSTRAINT chk_pwie_processing_started_pair CHECK (
                    (processing_status IN ('received') AND processing_started_at IS NULL AND attempt_count = 0)
                    OR (processing_status <> 'received')
                ),
                ADD CONSTRAINT chk_pwie_last_failure_reason_code CHECK (
                    last_failure_reason_code IS NULL
                    OR last_failure_reason_code REGEXP BINARY '^[a-z][a-z0-9_]{1,63}$'
                )
            SQL);

        $this->addSql('CREATE INDEX idx_pwie_status_retry ON payment_webhook_inbox_events (processing_status, next_retry_at)');
        $this->addSql('CREATE INDEX idx_pwie_status_lease ON payment_webhook_inbox_events (processing_status, lease_expires_at)');

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
                IF OLD.processing_status IN ('processed', 'rejected', 'dead_letter')
                   AND NEW.processing_status <> OLD.processing_status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_webhook_inbox_events terminal status cannot change';
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
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Version20260914130000 is irreversible to avoid weakening webhook recovery guarantees.');
    }
}
