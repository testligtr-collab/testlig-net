<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.17: commerce payment / subscription / fulfillment foundation.
 *
 * Creates the commercial offer catalog, orders and priced lines, provider-neutral payment
 * attempts with an append-only event log, subscriptions, refunds, and the fulfillment
 * bridge that ties a captured payment to exactly one AccessLicense.
 *
 * Money is always integer minor units (BIGINT) plus an ISO 4217 CHAR(3) currency — never
 * a float. No card data, provider secrets, or raw idempotency keys are stored anywhere:
 * only opaque provider references and HMAC digests.
 *
 * Irreversible — down() refuses restore.
 * Does not modify Version20260912170000 or earlier migrations.
 * No @testlig session bypass and no FOREIGN_KEY_CHECKS toggling.
 */
final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create commercial offer, order, payment, subscription, refund, and fulfillment tables with CHECKs and triggers.';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'commercial_offers',
            'commerce_orders',
            'commerce_order_items',
            'payment_attempts',
            'payment_events',
            'commerce_subscriptions',
            'commerce_fulfillments',
            'payment_refunds',
        ] as $tableName) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($tableName),
            );
            $this->abortIf(
                $exists > 0,
                \sprintf('Version20260913120000 preflight failed: table %s already exists.', $tableName),
            );
        }

        foreach ([
            'users',
            'institutions',
            'institution_memberships',
            'access_packages',
            'access_package_versions',
            'access_licenses',
        ] as $required) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($required),
            );
            $this->abortIf(0 === $exists, \sprintf('Version20260913120000 requires table %s.', $required));
        }

        $purchaseLicenses = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM access_licenses WHERE source_type = 'purchase'",
        );
        $this->write(\sprintf(
            'Preflight: existing purchase-source licenses=%d (left untouched; commerce tables start empty).',
            $purchaseLicenses,
        ));

        $this->createCommercialOffers();
        $this->createCommerceOrders();
        $this->createCommerceOrderItems();
        $this->createPaymentAttempts();
        $this->createPaymentEvents();
        $this->createCommerceSubscriptions();
        $this->createCommerceFulfillments();
        $this->createPaymentRefunds();
        $this->createForeignKeys();
        $this->alignDoctrineIndexNames();
        $this->createOfferTriggers();
        $this->createOrderTriggers();
        $this->createPaymentAttemptTriggers();
        $this->createPaymentEventTriggers();
        $this->createSubscriptionTriggers();
        $this->createFulfillmentTriggers();
        $this->createRefundTriggers();
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Version20260913120000 is an irreversible security migration.');
    }

    private function createCommercialOffers(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commercial_offers (
                id BINARY(16) NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                package_id BINARY(16) NOT NULL,
                package_version_id BINARY(16) NOT NULL,
                target_type VARCHAR(32) NOT NULL,
                billing_type VARCHAR(32) NOT NULL,
                billing_interval VARCHAR(32) DEFAULT NULL,
                price_amount_minor BIGINT NOT NULL,
                currency CHAR(3) NOT NULL,
                tax_rate_basis_points INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                valid_from DATETIME DEFAULT NULL,
                valid_until DATETIME DEFAULT NULL,
                offer_hash VARCHAR(64) NOT NULL,
                schema_version INT NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                activated_by_id BINARY(16) DEFAULT NULL,
                retired_by_id BINARY(16) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                activated_at DATETIME DEFAULT NULL,
                retired_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_co_code (code),
                UNIQUE INDEX uniq_co_id_package (id, package_id),
                UNIQUE INDEX uniq_co_id_package_version (id, package_version_id),
                UNIQUE INDEX uniq_co_id_currency (id, currency),
                INDEX idx_co_status_target (status, target_type),
                INDEX idx_co_package_status (package_id, status),
                INDEX idx_co_valid_range (valid_from, valid_until),
                INDEX idx_co_created_by (created_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_co_code CHECK (code REGEXP '^[a-z][a-z0-9_]{1,63}$'),
                CONSTRAINT chk_co_target_type CHECK (target_type IN ('individual', 'institution')),
                CONSTRAINT chk_co_billing_type CHECK (billing_type IN ('one_time', 'recurring')),
                CONSTRAINT chk_co_billing_pair CHECK (
                    (billing_type = 'recurring' AND billing_interval IN ('monthly', 'yearly'))
                    OR (billing_type = 'one_time' AND billing_interval IS NULL)
                ),
                CONSTRAINT chk_co_price CHECK (price_amount_minor > 0 AND price_amount_minor <= 999999999999),
                CONSTRAINT chk_co_currency CHECK (currency REGEXP BINARY '^[A-Z]{3}$'),
                CONSTRAINT chk_co_tax_rate CHECK (tax_rate_basis_points BETWEEN 0 AND 10000),
                CONSTRAINT chk_co_status CHECK (status IN ('draft', 'active', 'retired')),
                CONSTRAINT chk_co_schema_version CHECK (schema_version >= 1),
                CONSTRAINT chk_co_validity_window CHECK (
                    valid_from IS NULL OR valid_until IS NULL OR valid_until > valid_from
                ),
                CONSTRAINT chk_co_offer_hash CHECK (
                    CHAR_LENGTH(offer_hash) = 64 AND offer_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_co_lifecycle CHECK (
                    (status = 'draft' AND activated_at IS NULL AND activated_by_id IS NULL
                        AND retired_at IS NULL AND retired_by_id IS NULL)
                    OR (status = 'active' AND activated_at IS NOT NULL AND activated_by_id IS NOT NULL
                        AND retired_at IS NULL AND retired_by_id IS NULL)
                    OR (status = 'retired' AND retired_at IS NOT NULL AND retired_by_id IS NOT NULL)
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createCommerceOrders(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_orders (
                id BINARY(16) NOT NULL,
                public_reference VARCHAR(40) NOT NULL,
                purchaser_type VARCHAR(32) NOT NULL,
                user_id BINARY(16) DEFAULT NULL,
                institution_id BINARY(16) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                currency CHAR(3) NOT NULL,
                subtotal_amount_minor BIGINT NOT NULL,
                discount_amount_minor BIGINT NOT NULL,
                tax_amount_minor BIGINT NOT NULL,
                grand_total_amount_minor BIGINT NOT NULL,
                order_hash VARCHAR(64) NOT NULL,
                schema_version INT NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                payment_started_at DATETIME DEFAULT NULL,
                paid_at DATETIME DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                expired_at DATETIME DEFAULT NULL,
                failed_at DATETIME DEFAULT NULL,
                cancellation_reason_code VARCHAR(64) DEFAULT NULL,
                UNIQUE INDEX uniq_cord_public_reference (public_reference),
                UNIQUE INDEX uniq_cord_id_user (id, user_id),
                UNIQUE INDEX uniq_cord_id_institution (id, institution_id),
                UNIQUE INDEX uniq_cord_id_currency (id, currency),
                INDEX idx_cord_user_status (user_id, status),
                INDEX idx_cord_institution_status (institution_id, status),
                INDEX idx_cord_status_expires (status, expires_at),
                INDEX idx_cord_created_by (created_by_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_cord_public_reference CHECK (public_reference REGEXP BINARY '^ORD-[0-9A-F]{24}$'),
                CONSTRAINT chk_cord_purchaser_type CHECK (purchaser_type IN ('user', 'institution')),
                CONSTRAINT chk_cord_null_pair CHECK (
                    (purchaser_type = 'user' AND user_id IS NOT NULL AND institution_id IS NULL)
                    OR (purchaser_type = 'institution' AND institution_id IS NOT NULL AND user_id IS NULL)
                ),
                CONSTRAINT chk_cord_status CHECK (
                    status IN ('draft', 'awaiting_payment', 'paid', 'cancelled', 'expired', 'failed')
                ),
                CONSTRAINT chk_cord_currency CHECK (currency REGEXP BINARY '^[A-Z]{3}$'),
                CONSTRAINT chk_cord_amount_bounds CHECK (
                    subtotal_amount_minor BETWEEN 0 AND 999999999999
                    AND discount_amount_minor BETWEEN 0 AND 999999999999
                    AND tax_amount_minor BETWEEN 0 AND 999999999999
                    AND grand_total_amount_minor BETWEEN 0 AND 999999999999
                ),
                CONSTRAINT chk_cord_discount_cap CHECK (discount_amount_minor <= subtotal_amount_minor),
                CONSTRAINT chk_cord_grand_total CHECK (
                    grand_total_amount_minor = subtotal_amount_minor - discount_amount_minor + tax_amount_minor
                ),
                CONSTRAINT chk_cord_sealed_total CHECK (
                    status = 'draft' OR grand_total_amount_minor > 0
                ),
                CONSTRAINT chk_cord_order_hash CHECK (
                    CHAR_LENGTH(order_hash) = 64 AND order_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_cord_schema_version CHECK (schema_version >= 1),
                CONSTRAINT chk_cord_reason_code CHECK (
                    cancellation_reason_code IS NULL
                    OR cancellation_reason_code REGEXP '^[a-z][a-z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_cord_paid_pair CHECK (
                    (status = 'paid' AND paid_at IS NOT NULL) OR (status <> 'paid' AND paid_at IS NULL)
                ),
                CONSTRAINT chk_cord_cancelled_pair CHECK (
                    (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancellation_reason_code IS NOT NULL)
                    OR (status <> 'cancelled' AND cancelled_at IS NULL)
                ),
                CONSTRAINT chk_cord_expired_pair CHECK (
                    (status = 'expired' AND expired_at IS NOT NULL AND cancellation_reason_code IS NOT NULL)
                    OR (status <> 'expired' AND expired_at IS NULL)
                ),
                CONSTRAINT chk_cord_failed_pair CHECK (
                    (status = 'failed' AND failed_at IS NOT NULL) OR (status <> 'failed' AND failed_at IS NULL)
                ),
                CONSTRAINT chk_cord_payment_started CHECK (
                    status = 'draft' OR payment_started_at IS NULL OR payment_started_at >= created_at
                ),
                CONSTRAINT chk_cord_expires_at CHECK (expires_at > created_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createCommerceOrderItems(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_order_items (
                id BINARY(16) NOT NULL,
                order_id BINARY(16) NOT NULL,
                offer_id BINARY(16) NOT NULL,
                package_id BINARY(16) NOT NULL,
                package_version_id BINARY(16) NOT NULL,
                quantity INT NOT NULL,
                billing_type VARCHAR(32) NOT NULL,
                billing_interval VARCHAR(32) DEFAULT NULL,
                currency CHAR(3) NOT NULL,
                unit_price_amount_minor BIGINT NOT NULL,
                unit_tax_amount_minor BIGINT NOT NULL,
                tax_rate_basis_points INT NOT NULL,
                line_subtotal_amount_minor BIGINT NOT NULL,
                line_tax_amount_minor BIGINT NOT NULL,
                line_total_amount_minor BIGINT NOT NULL,
                offer_snapshot_hash VARCHAR(64) NOT NULL,
                package_policy_snapshot_hash VARCHAR(64) NOT NULL,
                description_snapshot VARCHAR(200) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_coi_order_offer (order_id, offer_id),
                UNIQUE INDEX uniq_coi_id_order (id, order_id),
                INDEX idx_coi_order (order_id),
                INDEX idx_coi_offer (offer_id),
                INDEX idx_coi_package_version (package_version_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_coi_quantity CHECK (quantity BETWEEN 1 AND 10),
                CONSTRAINT chk_coi_billing_type CHECK (billing_type IN ('one_time', 'recurring')),
                CONSTRAINT chk_coi_billing_pair CHECK (
                    (billing_type = 'recurring' AND billing_interval IN ('monthly', 'yearly') AND quantity = 1)
                    OR (billing_type = 'one_time' AND billing_interval IS NULL)
                ),
                CONSTRAINT chk_coi_currency CHECK (currency REGEXP BINARY '^[A-Z]{3}$'),
                CONSTRAINT chk_coi_tax_rate CHECK (tax_rate_basis_points BETWEEN 0 AND 10000),
                CONSTRAINT chk_coi_amount_bounds CHECK (
                    unit_price_amount_minor > 0 AND unit_price_amount_minor <= 999999999999
                    AND unit_tax_amount_minor BETWEEN 0 AND 999999999999
                    AND line_subtotal_amount_minor > 0 AND line_subtotal_amount_minor <= 999999999999
                    AND line_tax_amount_minor BETWEEN 0 AND 999999999999
                    AND line_total_amount_minor > 0 AND line_total_amount_minor <= 999999999999
                ),
                CONSTRAINT chk_coi_line_subtotal CHECK (
                    line_subtotal_amount_minor = unit_price_amount_minor * quantity
                ),
                CONSTRAINT chk_coi_line_total CHECK (
                    line_total_amount_minor = line_subtotal_amount_minor + line_tax_amount_minor
                ),
                CONSTRAINT chk_coi_line_tax CHECK (
                    line_tax_amount_minor
                        = FLOOR((line_subtotal_amount_minor * tax_rate_basis_points + 5000) / 10000)
                ),
                CONSTRAINT chk_coi_offer_hash CHECK (
                    CHAR_LENGTH(offer_snapshot_hash) = 64
                    AND offer_snapshot_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_coi_policy_hash CHECK (
                    CHAR_LENGTH(package_policy_snapshot_hash) = 64
                    AND package_policy_snapshot_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_coi_description CHECK (CHAR_LENGTH(description_snapshot) >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createPaymentAttempts(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_attempts (
                id BINARY(16) NOT NULL,
                order_id BINARY(16) NOT NULL,
                attempt_number INT NOT NULL,
                provider_code VARCHAR(32) NOT NULL,
                environment VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                amount_minor BIGINT NOT NULL,
                currency CHAR(3) NOT NULL,
                idempotency_key_hash VARCHAR(64) NOT NULL,
                provider_payment_reference VARCHAR(128) DEFAULT NULL,
                provider_authorization_reference VARCHAR(128) DEFAULT NULL,
                failure_code VARCHAR(64) DEFAULT NULL,
                event_sequence INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                authorized_at DATETIME DEFAULT NULL,
                captured_at DATETIME DEFAULT NULL,
                failed_at DATETIME DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_pa_order_attempt_number (order_id, attempt_number),
                UNIQUE INDEX uniq_pa_idempotency_key_hash (idempotency_key_hash),
                UNIQUE INDEX uniq_pa_provider_payment_reference (provider_code, provider_payment_reference),
                UNIQUE INDEX uniq_pa_id_order (id, order_id),
                INDEX idx_pa_order_status (order_id, status),
                INDEX idx_pa_provider_env (provider_code, environment),
                PRIMARY KEY (id),
                CONSTRAINT chk_pa_attempt_number CHECK (attempt_number >= 1),
                CONSTRAINT chk_pa_provider_code CHECK (provider_code REGEXP '^[a-z][a-z0-9_]{1,31}$'),
                CONSTRAINT chk_pa_environment CHECK (environment IN ('sandbox', 'production')),
                CONSTRAINT chk_pa_status CHECK (
                    status IN ('initiated', 'authorized', 'captured', 'failed', 'cancelled')
                ),
                CONSTRAINT chk_pa_amount CHECK (amount_minor > 0 AND amount_minor <= 999999999999),
                CONSTRAINT chk_pa_currency CHECK (currency REGEXP BINARY '^[A-Z]{3}$'),
                CONSTRAINT chk_pa_idempotency_hash CHECK (
                    CHAR_LENGTH(idempotency_key_hash) = 64
                    AND idempotency_key_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_pa_failure_code CHECK (
                    failure_code IS NULL OR failure_code REGEXP '^[a-z][a-z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_pa_event_sequence CHECK (event_sequence >= 0),
                CONSTRAINT chk_pa_authorized_pair CHECK (
                    (status = 'initiated' AND authorized_at IS NULL)
                    OR (status = 'authorized' AND authorized_at IS NOT NULL)
                    OR status IN ('captured', 'failed', 'cancelled')
                ),
                CONSTRAINT chk_pa_captured_pair CHECK (
                    (status = 'captured' AND captured_at IS NOT NULL)
                    OR (status <> 'captured' AND captured_at IS NULL)
                ),
                CONSTRAINT chk_pa_failed_pair CHECK (
                    (status = 'failed' AND failed_at IS NOT NULL AND failure_code IS NOT NULL)
                    OR (status <> 'failed' AND failed_at IS NULL)
                ),
                CONSTRAINT chk_pa_cancelled_pair CHECK (
                    (status = 'cancelled' AND cancelled_at IS NOT NULL AND failure_code IS NOT NULL)
                    OR (status <> 'cancelled' AND cancelled_at IS NULL)
                ),
                CONSTRAINT chk_pa_provider_reference_shape CHECK (
                    (provider_payment_reference IS NULL
                        OR provider_payment_reference REGEXP BINARY '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')
                    AND (provider_authorization_reference IS NULL
                        OR provider_authorization_reference REGEXP BINARY '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createPaymentEvents(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_events (
                id BINARY(16) NOT NULL,
                attempt_id BINARY(16) NOT NULL,
                sequence_number INT NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                provider_event_reference VARCHAR(128) DEFAULT NULL,
                idempotency_key_hash VARCHAR(64) NOT NULL,
                occurred_at DATETIME NOT NULL,
                received_at DATETIME NOT NULL,
                amount_minor BIGINT DEFAULT NULL,
                currency CHAR(3) DEFAULT NULL,
                sanitized_metadata JSON NOT NULL,
                event_hash VARCHAR(64) NOT NULL,
                previous_event_hash VARCHAR(64) DEFAULT NULL,
                UNIQUE INDEX uniq_pe_attempt_sequence (attempt_id, sequence_number),
                UNIQUE INDEX uniq_pe_idempotency_key_hash (idempotency_key_hash),
                UNIQUE INDEX uniq_pe_event_hash (event_hash),
                UNIQUE INDEX uniq_pe_provider_event_reference (attempt_id, provider_event_reference),
                INDEX idx_pe_attempt_type (attempt_id, event_type),
                INDEX idx_pe_occurred_at (occurred_at),
                PRIMARY KEY (id),
                CONSTRAINT chk_pe_sequence_number CHECK (sequence_number >= 1),
                CONSTRAINT chk_pe_event_type CHECK (
                    event_type IN ('authorized', 'captured', 'failed', 'cancelled',
                                   'refund_requested', 'refund_succeeded', 'refund_failed')
                ),
                CONSTRAINT chk_pe_amount_pair CHECK (
                    (amount_minor IS NULL AND currency IS NULL)
                    OR (amount_minor > 0 AND amount_minor <= 999999999999 AND currency REGEXP BINARY '^[A-Z]{3}$')
                ),
                CONSTRAINT chk_pe_amount_required CHECK (
                    event_type NOT IN ('authorized', 'captured', 'refund_requested', 'refund_succeeded')
                    OR amount_minor IS NOT NULL
                ),
                CONSTRAINT chk_pe_received_at CHECK (received_at >= occurred_at),
                CONSTRAINT chk_pe_idempotency_hash CHECK (
                    CHAR_LENGTH(idempotency_key_hash) = 64
                    AND idempotency_key_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_pe_event_hash CHECK (
                    CHAR_LENGTH(event_hash) = 64 AND event_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_pe_previous_event_hash CHECK (
                    previous_event_hash IS NULL
                    OR (CHAR_LENGTH(previous_event_hash) = 64
                        AND previous_event_hash REGEXP BINARY '^[0-9a-f]{64}$')
                ),
                CONSTRAINT chk_pe_chain_root CHECK (
                    (sequence_number = 1 AND previous_event_hash IS NULL)
                    OR (sequence_number > 1 AND previous_event_hash IS NOT NULL)
                ),
                CONSTRAINT chk_pe_provider_reference_shape CHECK (
                    provider_event_reference IS NULL
                    OR provider_event_reference REGEXP BINARY '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createCommerceSubscriptions(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_subscriptions (
                id BINARY(16) NOT NULL,
                subscriber_type VARCHAR(32) NOT NULL,
                user_id BINARY(16) DEFAULT NULL,
                institution_id BINARY(16) DEFAULT NULL,
                order_id BINARY(16) NOT NULL,
                offer_id BINARY(16) NOT NULL,
                package_id BINARY(16) NOT NULL,
                package_version_id BINARY(16) NOT NULL,
                billing_interval VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                current_period_start DATETIME NOT NULL,
                current_period_end DATETIME NOT NULL,
                period_number INT NOT NULL,
                cancel_at_period_end TINYINT(1) NOT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                cancellation_reason_code VARCHAR(64) DEFAULT NULL,
                provider_code VARCHAR(32) DEFAULT NULL,
                provider_subscription_reference VARCHAR(128) DEFAULT NULL,
                subscription_hash VARCHAR(64) NOT NULL,
                schema_version INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                activated_at DATETIME DEFAULT NULL,
                expired_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_cs_order_offer (order_id, offer_id),
                UNIQUE INDEX uniq_cs_id_order (id, order_id),
                UNIQUE INDEX uniq_cs_provider_reference (provider_code, provider_subscription_reference),
                INDEX idx_cs_user_status (user_id, status),
                INDEX idx_cs_institution_status (institution_id, status),
                INDEX idx_cs_period (current_period_start, current_period_end),
                PRIMARY KEY (id),
                CONSTRAINT chk_cs_subscriber_type CHECK (subscriber_type IN ('user', 'institution')),
                CONSTRAINT chk_cs_null_pair CHECK (
                    (subscriber_type = 'user' AND user_id IS NOT NULL AND institution_id IS NULL)
                    OR (subscriber_type = 'institution' AND institution_id IS NOT NULL AND user_id IS NULL)
                ),
                CONSTRAINT chk_cs_billing_interval CHECK (billing_interval IN ('monthly', 'yearly')),
                CONSTRAINT chk_cs_status CHECK (
                    status IN ('pending', 'active', 'past_due', 'cancelled', 'expired')
                ),
                CONSTRAINT chk_cs_period_range CHECK (current_period_end > current_period_start),
                CONSTRAINT chk_cs_period_number CHECK (period_number >= 1),
                CONSTRAINT chk_cs_cancel_flag CHECK (cancel_at_period_end IN (0, 1)),
                CONSTRAINT chk_cs_cancelled_pair CHECK (
                    (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancellation_reason_code IS NOT NULL)
                    OR (status <> 'cancelled' AND cancelled_at IS NULL)
                ),
                CONSTRAINT chk_cs_expired_pair CHECK (
                    (status = 'expired' AND expired_at IS NOT NULL)
                    OR (status <> 'expired' AND expired_at IS NULL)
                ),
                CONSTRAINT chk_cs_activated_pair CHECK (
                    status = 'pending' OR activated_at IS NOT NULL
                ),
                CONSTRAINT chk_cs_pending_not_activated CHECK (
                    status <> 'pending' OR activated_at IS NULL
                ),
                CONSTRAINT chk_cs_reason_code CHECK (
                    cancellation_reason_code IS NULL
                    OR cancellation_reason_code REGEXP '^[a-z][a-z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_cs_provider_pair CHECK (
                    (provider_code IS NULL AND provider_subscription_reference IS NULL)
                    OR (provider_code REGEXP '^[a-z][a-z0-9_]{1,31}$'
                        AND provider_subscription_reference REGEXP BINARY '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')
                ),
                CONSTRAINT chk_cs_subscription_hash CHECK (
                    CHAR_LENGTH(subscription_hash) = 64
                    AND subscription_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_cs_schema_version CHECK (schema_version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createCommerceFulfillments(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_fulfillments (
                id BINARY(16) NOT NULL,
                order_id BINARY(16) NOT NULL,
                order_item_id BINARY(16) NOT NULL,
                payment_attempt_id BINARY(16) NOT NULL,
                subscription_id BINARY(16) DEFAULT NULL,
                access_license_id BINARY(16) DEFAULT NULL,
                fulfillment_number INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                idempotency_key_hash VARCHAR(64) NOT NULL,
                period_key VARCHAR(8) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                fulfilled_at DATETIME DEFAULT NULL,
                reversed_at DATETIME DEFAULT NULL,
                failure_code VARCHAR(64) DEFAULT NULL,
                reversal_reason_code VARCHAR(64) DEFAULT NULL,
                completed_one_time_scope VARCHAR(40) AS (
                    CASE
                        WHEN status = 'completed' AND subscription_id IS NULL THEN LOWER(HEX(order_item_id))
                        ELSE NULL
                    END
                ) STORED,
                completed_period_scope VARCHAR(48) AS (
                    CASE
                        WHEN status = 'completed' AND subscription_id IS NOT NULL
                            THEN CONCAT(LOWER(HEX(subscription_id)), ':', period_key)
                        ELSE NULL
                    END
                ) STORED,
                UNIQUE INDEX uniq_cf_order_number (order_id, fulfillment_number),
                UNIQUE INDEX uniq_cf_idempotency_key_hash (idempotency_key_hash),
                UNIQUE INDEX uniq_cf_license (access_license_id),
                UNIQUE INDEX uniq_cf_completed_one_time (completed_one_time_scope),
                UNIQUE INDEX uniq_cf_completed_period (completed_period_scope),
                INDEX idx_cf_order_status (order_id, status),
                INDEX idx_cf_order_item (order_item_id),
                INDEX idx_cf_attempt (payment_attempt_id),
                INDEX idx_cf_subscription_period (subscription_id, period_key),
                PRIMARY KEY (id),
                CONSTRAINT chk_cf_fulfillment_number CHECK (fulfillment_number >= 1),
                CONSTRAINT chk_cf_status CHECK (status IN ('pending', 'completed', 'failed', 'reversed')),
                CONSTRAINT chk_cf_idempotency_hash CHECK (
                    CHAR_LENGTH(idempotency_key_hash) = 64
                    AND idempotency_key_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_cf_period_pair CHECK (
                    (subscription_id IS NULL AND period_key IS NULL)
                    OR (subscription_id IS NOT NULL AND period_key REGEXP BINARY '^[0-9]{8}$')
                ),
                CONSTRAINT chk_cf_license_pair CHECK (
                    (status IN ('pending', 'failed') AND access_license_id IS NULL)
                    OR (status IN ('completed', 'reversed') AND access_license_id IS NOT NULL)
                ),
                CONSTRAINT chk_cf_fulfilled_pair CHECK (
                    (status IN ('completed', 'reversed') AND fulfilled_at IS NOT NULL)
                    OR (status IN ('pending', 'failed') AND fulfilled_at IS NULL)
                ),
                CONSTRAINT chk_cf_reversed_pair CHECK (
                    (status = 'reversed' AND reversed_at IS NOT NULL AND reversal_reason_code IS NOT NULL)
                    OR (status <> 'reversed' AND reversed_at IS NULL AND reversal_reason_code IS NULL)
                ),
                CONSTRAINT chk_cf_failed_pair CHECK (
                    (status = 'failed' AND failure_code IS NOT NULL)
                    OR (status <> 'failed' AND failure_code IS NULL)
                ),
                CONSTRAINT chk_cf_failure_code CHECK (
                    failure_code IS NULL OR failure_code REGEXP '^[a-z][a-z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_cf_reversal_reason CHECK (
                    reversal_reason_code IS NULL OR reversal_reason_code REGEXP '^[a-z][a-z0-9_]{1,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createPaymentRefunds(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_refunds (
                id BINARY(16) NOT NULL,
                payment_attempt_id BINARY(16) NOT NULL,
                refund_number INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                amount_minor BIGINT NOT NULL,
                currency CHAR(3) NOT NULL,
                reason_code VARCHAR(64) NOT NULL,
                provider_refund_reference VARCHAR(128) DEFAULT NULL,
                idempotency_key_hash VARCHAR(64) NOT NULL,
                failure_code VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                succeeded_at DATETIME DEFAULT NULL,
                failed_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_pr_attempt_refund_number (payment_attempt_id, refund_number),
                UNIQUE INDEX uniq_pr_idempotency_key_hash (idempotency_key_hash),
                UNIQUE INDEX uniq_pr_provider_reference (payment_attempt_id, provider_refund_reference),
                INDEX idx_pr_attempt_status (payment_attempt_id, status),
                PRIMARY KEY (id),
                CONSTRAINT chk_pr_refund_number CHECK (refund_number >= 1),
                CONSTRAINT chk_pr_status CHECK (status IN ('requested', 'succeeded', 'failed')),
                CONSTRAINT chk_pr_amount CHECK (amount_minor > 0 AND amount_minor <= 999999999999),
                CONSTRAINT chk_pr_currency CHECK (currency REGEXP BINARY '^[A-Z]{3}$'),
                CONSTRAINT chk_pr_reason_code CHECK (
                    reason_code IN ('purchaser_requested', 'duplicate_charge', 'service_not_delivered',
                                    'suspected_fraud', 'price_adjustment', 'administrative_refund')
                ),
                CONSTRAINT chk_pr_idempotency_hash CHECK (
                    CHAR_LENGTH(idempotency_key_hash) = 64
                    AND idempotency_key_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_pr_provider_reference_shape CHECK (
                    provider_refund_reference IS NULL
                    OR provider_refund_reference REGEXP BINARY '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                ),
                CONSTRAINT chk_pr_succeeded_pair CHECK (
                    (status = 'succeeded' AND succeeded_at IS NOT NULL)
                    OR (status <> 'succeeded' AND succeeded_at IS NULL)
                ),
                CONSTRAINT chk_pr_failed_pair CHECK (
                    (status = 'failed' AND failed_at IS NOT NULL AND failure_code IS NOT NULL)
                    OR (status <> 'failed' AND failed_at IS NULL AND failure_code IS NULL)
                ),
                CONSTRAINT chk_pr_failure_code CHECK (
                    failure_code IS NULL OR failure_code REGEXP '^[a-z][a-z0-9_]{1,63}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    private function createForeignKeys(): void
    {
        $this->addSql('ALTER TABLE commercial_offers ADD CONSTRAINT FK_CO_PACKAGE FOREIGN KEY (package_id) REFERENCES access_packages (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commercial_offers ADD CONSTRAINT FK_CO_PACKAGE_VERSION FOREIGN KEY (package_version_id) REFERENCES access_package_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commercial_offers ADD CONSTRAINT FK_CO_PACKAGE_VERSION_PACKAGE FOREIGN KEY (package_version_id, package_id) REFERENCES access_package_versions (id, package_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commercial_offers ADD CONSTRAINT FK_CO_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commercial_offers ADD CONSTRAINT FK_CO_ACTIVATED_BY FOREIGN KEY (activated_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commercial_offers ADD CONSTRAINT FK_CO_RETIRED_BY FOREIGN KEY (retired_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE commerce_orders ADD CONSTRAINT FK_CORD_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_orders ADD CONSTRAINT FK_CORD_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_orders ADD CONSTRAINT FK_CORD_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE commerce_order_items ADD CONSTRAINT FK_COI_ORDER FOREIGN KEY (order_id) REFERENCES commerce_orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_order_items ADD CONSTRAINT FK_COI_OFFER FOREIGN KEY (offer_id) REFERENCES commercial_offers (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_order_items ADD CONSTRAINT FK_COI_PACKAGE FOREIGN KEY (package_id) REFERENCES access_packages (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_order_items ADD CONSTRAINT FK_COI_PACKAGE_VERSION FOREIGN KEY (package_version_id) REFERENCES access_package_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_order_items ADD CONSTRAINT FK_COI_OFFER_PACKAGE FOREIGN KEY (offer_id, package_id) REFERENCES commercial_offers (id, package_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_order_items ADD CONSTRAINT FK_COI_OFFER_PACKAGE_VERSION FOREIGN KEY (offer_id, package_version_id) REFERENCES commercial_offers (id, package_version_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_order_items ADD CONSTRAINT FK_COI_ORDER_CURRENCY FOREIGN KEY (order_id, currency) REFERENCES commerce_orders (id, currency) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE payment_attempts ADD CONSTRAINT FK_PA_ORDER FOREIGN KEY (order_id) REFERENCES commerce_orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_attempts ADD CONSTRAINT FK_PA_ORDER_CURRENCY FOREIGN KEY (order_id, currency) REFERENCES commerce_orders (id, currency) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE payment_events ADD CONSTRAINT FK_PE_ATTEMPT FOREIGN KEY (attempt_id) REFERENCES payment_attempts (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_ORDER FOREIGN KEY (order_id) REFERENCES commerce_orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_OFFER FOREIGN KEY (offer_id) REFERENCES commercial_offers (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_PACKAGE FOREIGN KEY (package_id) REFERENCES access_packages (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_PACKAGE_VERSION FOREIGN KEY (package_version_id) REFERENCES access_package_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_OFFER_PACKAGE FOREIGN KEY (offer_id, package_id) REFERENCES commercial_offers (id, package_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_OFFER_PACKAGE_VERSION FOREIGN KEY (offer_id, package_version_id) REFERENCES commercial_offers (id, package_version_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_ORDER_USER FOREIGN KEY (order_id, user_id) REFERENCES commerce_orders (id, user_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_subscriptions ADD CONSTRAINT FK_CS_ORDER_INSTITUTION FOREIGN KEY (order_id, institution_id) REFERENCES commerce_orders (id, institution_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_ORDER FOREIGN KEY (order_id) REFERENCES commerce_orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_ORDER_ITEM FOREIGN KEY (order_item_id) REFERENCES commerce_order_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_ORDER_ITEM_ORDER FOREIGN KEY (order_item_id, order_id) REFERENCES commerce_order_items (id, order_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_ATTEMPT FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_ATTEMPT_ORDER FOREIGN KEY (payment_attempt_id, order_id) REFERENCES payment_attempts (id, order_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_SUBSCRIPTION FOREIGN KEY (subscription_id) REFERENCES commerce_subscriptions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_SUBSCRIPTION_ORDER FOREIGN KEY (subscription_id, order_id) REFERENCES commerce_subscriptions (id, order_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_fulfillments ADD CONSTRAINT FK_CF_LICENSE FOREIGN KEY (access_license_id) REFERENCES access_licenses (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_PR_ATTEMPT FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts (id) ON DELETE CASCADE');
    }

    /**
     * MariaDB names each foreign key's helper index after the constraint. Doctrine expects
     * its own hashed IDX_* names, so rename them here to keep `schema:update --dump-sql` empty.
     */
    private function alignDoctrineIndexNames(): void
    {
        foreach ([
            ['commercial_offers', 'fk_co_activated_by', 'IDX_96006A7BE00EB9A0'],
            ['commercial_offers', 'fk_co_retired_by', 'IDX_96006A7BAE9EDADF'],
            ['commercial_offers', 'fk_co_package_version_package', 'IDX_96006A7B47A0D2F0F44CABFF'],
            ['commerce_order_items', 'fk_coi_package', 'IDX_9B1AC83DF44CABFF'],
            ['commerce_order_items', 'fk_coi_offer_package', 'IDX_9B1AC83D53C674EEF44CABFF'],
            ['commerce_order_items', 'fk_coi_offer_package_version', 'IDX_9B1AC83D53C674EE47A0D2F0'],
            ['commerce_order_items', 'fk_coi_order_currency', 'IDX_9B1AC83D8D9F6D386956883F'],
            ['payment_attempts', 'fk_pa_order_currency', 'IDX_FF01A50C8D9F6D386956883F'],
            ['commerce_subscriptions', 'fk_cs_package', 'IDX_915B30E6F44CABFF'],
            ['commerce_subscriptions', 'fk_cs_package_version', 'IDX_915B30E647A0D2F0'],
            ['commerce_subscriptions', 'fk_cs_offer_package', 'IDX_915B30E653C674EEF44CABFF'],
            ['commerce_subscriptions', 'fk_cs_offer_package_version', 'IDX_915B30E653C674EE47A0D2F0'],
            ['commerce_subscriptions', 'fk_cs_order_user', 'IDX_915B30E68D9F6D38A76ED395'],
            ['commerce_subscriptions', 'fk_cs_order_institution', 'IDX_915B30E68D9F6D3810405986'],
            ['commerce_fulfillments', 'fk_cf_order_item_order', 'IDX_1555149DE415FB158D9F6D38'],
            ['commerce_fulfillments', 'fk_cf_attempt_order', 'IDX_1555149D84673FBE8D9F6D38'],
            ['commerce_fulfillments', 'fk_cf_subscription_order', 'IDX_1555149D9A1887DC8D9F6D38'],
        ] as [$table, $from, $to]) {
            $this->addSql(\sprintf('ALTER TABLE %s RENAME INDEX %s TO %s', $table, $from, $to));
        }
    }

    private function createOfferTriggers(): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_co_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_co_bu_immutable
            BEFORE UPDATE ON commercial_offers
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.code <> NEW.code
                   OR OLD.package_id <> NEW.package_id
                   OR OLD.package_version_id <> NEW.package_version_id
                   OR OLD.target_type <> NEW.target_type
                   OR OLD.currency <> NEW.currency
                   OR OLD.schema_version <> NEW.schema_version
                   OR OLD.created_by_id <> NEW.created_by_id
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commercial_offer identity fields are immutable';
                END IF;
                IF OLD.status <> 'draft' THEN
                    IF OLD.billing_type <> NEW.billing_type
                       OR NOT (OLD.billing_interval <=> NEW.billing_interval)
                       OR OLD.price_amount_minor <> NEW.price_amount_minor
                       OR OLD.tax_rate_basis_points <> NEW.tax_rate_basis_points
                       OR NOT (OLD.valid_from <=> NEW.valid_from)
                       OR NOT (OLD.valid_until <=> NEW.valid_until)
                       OR OLD.offer_hash <> NEW.offer_hash
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commercial_offer terms are immutable once published';
                    END IF;
                END IF;
                IF OLD.status = 'retired' AND NEW.status <> 'retired' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'retired commercial_offer cannot be reopened';
                END IF;
                IF OLD.status = 'active' AND NEW.status = 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active commercial_offer cannot return to draft';
                END IF;
            END
            SQL);
    }

    private function createOrderTriggers(): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_cord_bu_lifecycle');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_cord_bu_lifecycle
            BEFORE UPDATE ON commerce_orders
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.public_reference <> NEW.public_reference
                   OR OLD.purchaser_type <> NEW.purchaser_type
                   OR NOT (OLD.user_id <=> NEW.user_id)
                   OR NOT (OLD.institution_id <=> NEW.institution_id)
                   OR OLD.currency <> NEW.currency
                   OR OLD.schema_version <> NEW.schema_version
                   OR OLD.created_by_id <> NEW.created_by_id
                   OR OLD.created_at <> NEW.created_at
                   OR OLD.expires_at <> NEW.expires_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order identity fields are immutable';
                END IF;
                IF OLD.status <> 'draft' THEN
                    IF OLD.subtotal_amount_minor <> NEW.subtotal_amount_minor
                       OR OLD.discount_amount_minor <> NEW.discount_amount_minor
                       OR OLD.tax_amount_minor <> NEW.tax_amount_minor
                       OR OLD.grand_total_amount_minor <> NEW.grand_total_amount_minor
                       OR OLD.order_hash <> NEW.order_hash
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order totals are frozen after sealing';
                    END IF;
                END IF;
                IF OLD.status IN ('paid', 'cancelled', 'expired') AND NEW.status <> OLD.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order terminal status cannot change';
                END IF;
                IF OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'awaiting_payment', 'cancelled', 'expired') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'draft commerce_order must await payment first';
                END IF;
                IF OLD.status = 'failed' AND NEW.status NOT IN ('failed', 'awaiting_payment', 'cancelled', 'expired') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'failed commerce_order can only retry, cancel, or expire';
                END IF;
                IF NEW.status = 'paid' AND OLD.status <> 'awaiting_payment' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order can only be paid from awaiting_payment';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_coi_bu_immutable');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_coi_bu_immutable
            BEFORE UPDATE ON commerce_order_items
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order_items are immutable';
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_coi_bi_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_coi_bi_draft_only
            BEFORE INSERT ON commerce_order_items
            FOR EACH ROW
            BEGIN
                DECLARE o_status VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                DECLARE o_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                SELECT status, currency INTO o_status, o_currency FROM commerce_orders WHERE id = NEW.order_id;
                IF o_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order_item order not found';
                END IF;
                IF o_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order_items can only be added to draft orders';
                END IF;
                IF o_currency <> NEW.currency THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order_item currency must match the order';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_coi_bd_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_coi_bd_draft_only
            BEFORE DELETE ON commerce_order_items
            FOR EACH ROW
            BEGIN
                DECLARE o_status VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                SELECT status INTO o_status FROM commerce_orders WHERE id = OLD.order_id;
                IF o_status IS NOT NULL AND o_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_order_items can only be removed from draft orders';
                END IF;
            END
            SQL);
    }

    private function createPaymentAttemptTriggers(): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_pa_bi_order_state');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pa_bi_order_state
            BEFORE INSERT ON payment_attempts
            FOR EACH ROW
            BEGIN
                DECLARE o_status VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                DECLARE o_total BIGINT;
                DECLARE o_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                SELECT status, grand_total_amount_minor, currency
                  INTO o_status, o_total, o_currency
                  FROM commerce_orders WHERE id = NEW.order_id;
                IF o_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt order not found';
                END IF;
                IF o_status NOT IN ('awaiting_payment', 'failed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt requires an order awaiting payment';
                END IF;
                IF o_total <> NEW.amount_minor OR o_currency <> NEW.currency THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt amount must equal the order grand total';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pa_bu_lifecycle');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pa_bu_lifecycle
            BEFORE UPDATE ON payment_attempts
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.order_id <> NEW.order_id
                   OR OLD.attempt_number <> NEW.attempt_number
                   OR OLD.provider_code <> NEW.provider_code
                   OR OLD.environment <> NEW.environment
                   OR OLD.amount_minor <> NEW.amount_minor
                   OR OLD.currency <> NEW.currency
                   OR OLD.idempotency_key_hash <> NEW.idempotency_key_hash
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt identity fields are immutable';
                END IF;
                IF OLD.provider_payment_reference IS NOT NULL
                   AND NOT (OLD.provider_payment_reference <=> NEW.provider_payment_reference)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt provider reference is write-once';
                END IF;
                IF OLD.provider_authorization_reference IS NOT NULL
                   AND NOT (OLD.provider_authorization_reference <=> NEW.provider_authorization_reference)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt authorization reference is write-once';
                END IF;
                IF NEW.event_sequence < OLD.event_sequence THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt event_sequence cannot go backwards';
                END IF;
                IF OLD.status IN ('captured', 'failed', 'cancelled') AND NEW.status <> OLD.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt terminal status cannot change';
                END IF;
                IF NEW.status = 'authorized' AND OLD.status <> 'initiated' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt can only be authorized from initiated';
                END IF;
                IF NEW.status = 'captured' AND OLD.status NOT IN ('initiated', 'authorized') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_attempt capture requires initiated or authorized';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pa_bd_deny');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pa_bd_deny
            BEFORE DELETE ON payment_attempts
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'initiated' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'settled payment_attempts cannot be deleted';
                END IF;
            END
            SQL);
    }

    private function createPaymentEventTriggers(): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_pe_bi_chain');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pe_bi_chain
            BEFORE INSERT ON payment_events
            FOR EACH ROW
            BEGIN
                DECLARE a_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                DECLARE a_amount BIGINT;
                DECLARE last_seq INT;
                DECLARE last_hash VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                SELECT currency, amount_minor INTO a_currency, a_amount
                  FROM payment_attempts WHERE id = NEW.attempt_id;
                IF a_currency IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_event attempt not found';
                END IF;
                IF NEW.currency IS NOT NULL AND NEW.currency <> a_currency THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_event currency must match the attempt';
                END IF;
                IF NEW.amount_minor IS NOT NULL AND NEW.amount_minor > a_amount THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_event amount cannot exceed the attempt amount';
                END IF;
                SELECT sequence_number, event_hash INTO last_seq, last_hash
                  FROM payment_events WHERE attempt_id = NEW.attempt_id
                 ORDER BY sequence_number DESC LIMIT 1;
                IF last_seq IS NULL THEN
                    IF NEW.sequence_number <> 1 OR NEW.previous_event_hash IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'first payment_event must open the hash chain';
                    END IF;
                ELSE
                    IF NEW.sequence_number <> last_seq + 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_event sequence_number must be consecutive';
                    END IF;
                    IF NEW.previous_event_hash IS NULL OR NEW.previous_event_hash <> last_hash THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_event must chain to the previous event hash';
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pe_bu_append_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pe_bu_append_only
            BEFORE UPDATE ON payment_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_events are append-only';
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pe_bd_append_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pe_bd_append_only
            BEFORE DELETE ON payment_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_events cannot be deleted';
            END
            SQL);
    }

    private function createSubscriptionTriggers(): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_cs_bu_lifecycle');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_cs_bu_lifecycle
            BEFORE UPDATE ON commerce_subscriptions
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.subscriber_type <> NEW.subscriber_type
                   OR NOT (OLD.user_id <=> NEW.user_id)
                   OR NOT (OLD.institution_id <=> NEW.institution_id)
                   OR OLD.order_id <> NEW.order_id
                   OR OLD.offer_id <> NEW.offer_id
                   OR OLD.package_id <> NEW.package_id
                   OR OLD.package_version_id <> NEW.package_version_id
                   OR OLD.billing_interval <> NEW.billing_interval
                   OR OLD.schema_version <> NEW.schema_version
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription identity fields are immutable';
                END IF;
                IF OLD.status IN ('cancelled', 'expired') AND NEW.status <> OLD.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription terminal status cannot change';
                END IF;
                IF NEW.period_number < OLD.period_number THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription period_number cannot go backwards';
                END IF;
                IF NEW.period_number > OLD.period_number THEN
                    IF NEW.period_number <> OLD.period_number + 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription renewal must advance one period';
                    END IF;
                    IF NEW.current_period_start < OLD.current_period_end THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription periods must not overlap';
                    END IF;
                ELSE
                    IF OLD.current_period_start <> NEW.current_period_start
                       OR OLD.current_period_end <> NEW.current_period_end
                       OR OLD.subscription_hash <> NEW.subscription_hash
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription period changes require a renewal';
                    END IF;
                END IF;
                IF NEW.status = 'pending' AND OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription cannot return to pending';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_cs_bi_offer_recurring');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_cs_bi_offer_recurring
            BEFORE INSERT ON commerce_subscriptions
            FOR EACH ROW
            BEGIN
                DECLARE o_billing_type VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                DECLARE o_billing_interval VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                SELECT billing_type, billing_interval INTO o_billing_type, o_billing_interval
                  FROM commercial_offers WHERE id = NEW.offer_id;
                IF o_billing_type IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription offer not found';
                END IF;
                IF o_billing_type <> 'recurring' OR o_billing_interval <> NEW.billing_interval THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_subscription requires a matching recurring offer';
                END IF;
            END
            SQL);
    }

    private function createFulfillmentTriggers(): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_cf_bi_captured');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_cf_bi_captured
            BEFORE INSERT ON commerce_fulfillments
            FOR EACH ROW
            BEGIN
                DECLARE a_status VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                DECLARE a_order BINARY(16);
                DECLARE captured_events INT;
                SELECT status, order_id INTO a_status, a_order
                  FROM payment_attempts WHERE id = NEW.payment_attempt_id;
                IF a_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment payment attempt not found';
                END IF;
                IF a_status <> 'captured' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment requires a captured payment attempt';
                END IF;
                IF a_order <> NEW.order_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment attempt must belong to the order';
                END IF;
                SELECT COUNT(*) INTO captured_events
                  FROM payment_events
                 WHERE attempt_id = NEW.payment_attempt_id AND event_type = 'captured';
                IF captured_events = 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment requires a captured payment event';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_cf_bu_lifecycle');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_cf_bu_lifecycle
            BEFORE UPDATE ON commerce_fulfillments
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.order_id <> NEW.order_id
                   OR OLD.order_item_id <> NEW.order_item_id
                   OR OLD.payment_attempt_id <> NEW.payment_attempt_id
                   OR NOT (OLD.subscription_id <=> NEW.subscription_id)
                   OR OLD.fulfillment_number <> NEW.fulfillment_number
                   OR OLD.idempotency_key_hash <> NEW.idempotency_key_hash
                   OR NOT (OLD.period_key <=> NEW.period_key)
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment identity fields are immutable';
                END IF;
                IF OLD.access_license_id IS NOT NULL
                   AND NOT (OLD.access_license_id <=> NEW.access_license_id)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment license link is write-once';
                END IF;
                IF OLD.status IN ('failed', 'reversed') AND NEW.status <> OLD.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment terminal status cannot change';
                END IF;
                IF NEW.status = 'completed' AND OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment can only complete from pending';
                END IF;
                IF NEW.status = 'reversed' AND OLD.status <> 'completed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment can only reverse a completed row';
                END IF;
                IF NEW.status = 'pending' AND OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'commerce_fulfillment cannot return to pending';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_cf_bd_deny');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_cf_bd_deny
            BEFORE DELETE ON commerce_fulfillments
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('completed', 'reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'granted commerce_fulfillments cannot be deleted';
                END IF;
            END
            SQL);
    }

    private function createRefundTriggers(): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_pr_bi_cap');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pr_bi_cap
            BEFORE INSERT ON payment_refunds
            FOR EACH ROW
            BEGIN
                DECLARE a_status VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                DECLARE a_amount BIGINT;
                DECLARE a_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                DECLARE reserved BIGINT;
                SELECT status, amount_minor, currency INTO a_status, a_amount, a_currency
                  FROM payment_attempts WHERE id = NEW.payment_attempt_id;
                IF a_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refund attempt not found';
                END IF;
                IF a_status <> 'captured' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refund requires a captured payment attempt';
                END IF;
                IF a_currency <> NEW.currency THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refund currency must match the attempt';
                END IF;
                SELECT COALESCE(SUM(amount_minor), 0) INTO reserved
                  FROM payment_refunds
                 WHERE payment_attempt_id = NEW.payment_attempt_id AND status <> 'failed';
                IF reserved + NEW.amount_minor > a_amount THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refund total would exceed the captured amount';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pr_bu_lifecycle');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pr_bu_lifecycle
            BEFORE UPDATE ON payment_refunds
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.payment_attempt_id <> NEW.payment_attempt_id
                   OR OLD.refund_number <> NEW.refund_number
                   OR OLD.amount_minor <> NEW.amount_minor
                   OR OLD.currency <> NEW.currency
                   OR OLD.reason_code <> NEW.reason_code
                   OR OLD.idempotency_key_hash <> NEW.idempotency_key_hash
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refund identity fields are immutable';
                END IF;
                IF OLD.provider_refund_reference IS NOT NULL
                   AND NOT (OLD.provider_refund_reference <=> NEW.provider_refund_reference)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refund provider reference is write-once';
                END IF;
                IF OLD.status <> 'requested' AND NEW.status <> OLD.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_refund terminal status cannot change';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pr_bd_deny');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pr_bd_deny
            BEFORE DELETE ON payment_refunds
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'succeeded' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'succeeded payment_refunds cannot be deleted';
                END IF;
            END
            SQL);
    }
}
