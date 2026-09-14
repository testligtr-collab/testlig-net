<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.18 — payment webhook inbox (append-only, verified events only).
 */
final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payment_webhook_inbox_events with integrity CHECKs and lifecycle triggers.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->createSchemaManager()->tablesExist(['payment_attempts']),
            'payment_attempts must exist before payment_webhook_inbox_events.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE payment_webhook_inbox_events (
                id BINARY(16) NOT NULL,
                provider_code VARCHAR(64) NOT NULL,
                environment VARCHAR(16) NOT NULL,
                provider_event_reference VARCHAR(128) NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                payload_hash VARCHAR(64) NOT NULL,
                signature_fingerprint VARCHAR(64) NOT NULL,
                received_at DATETIME NOT NULL,
                provider_occurred_at DATETIME NOT NULL,
                processing_status VARCHAR(32) NOT NULL,
                processed_at DATETIME DEFAULT NULL,
                payment_attempt_id BINARY(16) DEFAULT NULL,
                failure_reason_code VARCHAR(64) DEFAULT NULL,
                schema_version INT NOT NULL,
                sanitized_metadata JSON NOT NULL,
                UNIQUE INDEX uniq_pwie_provider_env_event_ref (provider_code, environment, provider_event_reference),
                INDEX idx_pwie_status_received (processing_status, received_at),
                INDEX idx_pwie_attempt (payment_attempt_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_pwie_environment CHECK (environment IN ('sandbox', 'live')),
                CONSTRAINT chk_pwie_event_type CHECK (event_type IN (
                    'authorized', 'captured', 'failed', 'cancelled',
                    'refund_requested', 'refund_succeeded', 'refund_failed'
                )),
                CONSTRAINT chk_pwie_status CHECK (processing_status IN (
                    'received', 'processing', 'processed', 'rejected', 'failed'
                )),
                CONSTRAINT chk_pwie_payload_hash CHECK (
                    CHAR_LENGTH(payload_hash) = 64
                    AND payload_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_pwie_signature_fingerprint CHECK (
                    CHAR_LENGTH(signature_fingerprint) = 64
                    AND signature_fingerprint REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_pwie_provider_code CHECK (
                    provider_code REGEXP BINARY '^[a-z][a-z0-9_]{1,31}$'
                ),
                CONSTRAINT chk_pwie_provider_event_reference CHECK (
                    provider_event_reference REGEXP BINARY '^[A-Za-z0-9._:-]{8,128}$'
                ),
                CONSTRAINT chk_pwie_schema_version CHECK (schema_version >= 1),
                CONSTRAINT chk_pwie_processed_null_pair CHECK (
                    (processing_status IN ('received', 'processing') AND processed_at IS NULL AND failure_reason_code IS NULL)
                    OR (processing_status = 'processed' AND processed_at IS NOT NULL AND failure_reason_code IS NULL)
                    OR (processing_status IN ('rejected', 'failed') AND processed_at IS NOT NULL AND failure_reason_code IS NOT NULL)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE payment_webhook_inbox_events ADD CONSTRAINT FK_PWIE_ATTEMPT FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts (id) ON DELETE SET NULL');

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
                IF OLD.processing_status IN ('processed', 'rejected', 'failed') AND NEW.processing_status <> OLD.processing_status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_webhook_inbox_events terminal status cannot change';
                END IF;
                IF OLD.processing_status = 'received' AND NEW.processing_status NOT IN ('received', 'processing', 'rejected') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid payment_webhook_inbox_events status transition';
                END IF;
                IF OLD.processing_status = 'processing' AND NEW.processing_status NOT IN ('processing', 'processed', 'rejected', 'failed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid payment_webhook_inbox_events status transition';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pwie_bd_deny');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pwie_bd_deny
            BEFORE DELETE ON payment_webhook_inbox_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_webhook_inbox_events cannot be deleted';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_pwie_bd_deny');
        $this->addSql('DROP TRIGGER IF EXISTS trg_pwie_bu_lifecycle');
        $this->addSql('ALTER TABLE payment_webhook_inbox_events DROP FOREIGN KEY FK_PWIE_ATTEMPT');
        $this->addSql('DROP TABLE payment_webhook_inbox_events');
    }
}
