<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.17: restore always-deny DELETE triggers for append-only payment rows.
 *
 * Version20260913130000 experimented with parent-existence checks so CASCADE purge
 * could proceed when MariaDB invoked BEFORE DELETE triggers. That check races with
 * InnoDB cascade ordering (parent still visible) and broke order-root cleanup.
 * MariaDB does not fire child DELETE triggers for foreign-key CASCADE, so the
 * original always-deny bodies remain correct for both application deletes and
 * order-root CASCADE purge.
 *
 * Irreversible — down() refuses restore.
 */
final class Version20260913140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore always-deny DELETE triggers on payment_events, granted fulfillments, and succeeded refunds.';
    }

    public function up(Schema $schema): void
    {
        $events = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'payment_events'",
        );
        $this->abortIf(0 === $events, 'Version20260913140000 requires payment_events.');

        $this->addSql('DROP TRIGGER IF EXISTS trg_pe_bd_append_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_pe_bd_append_only
            BEFORE DELETE ON payment_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_events cannot be deleted';
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

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260913140000 is irreversible.',
        );
    }
}
