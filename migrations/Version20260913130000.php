<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.17 hardening: allow parent-cascade purge of append-only payment rows.
 *
 * Direct DELETE of payment_events and granted fulfillments stays denied while the parent
 * attempt/order still exists. MariaDB may invoke BEFORE DELETE triggers during
 * ON DELETE CASCADE; when the parent row is already gone the cascade purge must proceed
 * so test and operational cleanup can delete commerce_orders without weakening the
 * application-level append-only guarantee.
 *
 * Irreversible — down() refuses restore.
 * Does not modify Version20260913120000.
 * No @testlig session bypass and no FOREIGN_KEY_CHECKS toggling.
 */
final class Version20260913130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow payment_event and granted fulfillment DELETE only when the parent row is already gone (cascade purge).';
    }

    public function up(Schema $schema): void
    {
        $events = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'payment_events'",
        );
        $fulfillments = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'commerce_fulfillments'",
        );
        $this->abortIf(0 === $events, 'Version20260913130000 requires payment_events.');
        $this->abortIf(0 === $fulfillments, 'Version20260913130000 requires commerce_fulfillments.');

        $this->addSql('DROP TRIGGER IF EXISTS trg_pe_bd_append_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pe_bd_append_only
            BEFORE DELETE ON payment_events
            FOR EACH ROW
            BEGIN
                DECLARE attempt_exists INT DEFAULT 0;
                SELECT COUNT(*) INTO attempt_exists
                  FROM payment_attempts WHERE id = OLD.attempt_id;
                IF attempt_exists > 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_events cannot be deleted';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_cf_bd_deny');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_cf_bd_deny
            BEFORE DELETE ON commerce_fulfillments
            FOR EACH ROW
            BEGIN
                DECLARE order_exists INT DEFAULT 0;
                SELECT COUNT(*) INTO order_exists
                  FROM commerce_orders WHERE id = OLD.order_id;
                IF order_exists > 0 AND OLD.status IN ('completed', 'reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'granted commerce_fulfillments cannot be deleted';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_pr_bd_deny');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pr_bd_deny
            BEFORE DELETE ON payment_refunds
            FOR EACH ROW
            BEGIN
                DECLARE attempt_exists INT DEFAULT 0;
                SELECT COUNT(*) INTO attempt_exists
                  FROM payment_attempts WHERE id = OLD.payment_attempt_id;
                IF attempt_exists > 0 AND OLD.status = 'succeeded' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'succeeded payment_refunds cannot be deleted';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260913130000 is irreversible: restoring always-deny DELETE triggers would break cascade cleanup.',
        );
    }
}
