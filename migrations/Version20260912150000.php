<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.15 pass-2 integrity: media timestamp CHECKs/lifecycle, revision sequential + current sync,
 * published_at must match latest publication.
 *
 * Irreversible security migration — down() does not restore weaker CHECKs/triggers.
 *
 * Does not modify Version20260912120000, Version20260912130000, or Version20260912140000.
 */
final class Version20260912150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden stored_media timestamp CHECKs/lifecycle, sequential revision sync, published_at match';
    }

    public function up(Schema $schema): void
    {
        $readyMissingReadyAt = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM stored_media_assets
             WHERE status = 'ready' AND ready_at IS NULL
            SQL);
        $this->write(\sprintf('Preflight: ready without ready_at=%d', $readyMissingReadyAt));
        $this->abortIf(
            $readyMissingReadyAt > 0,
            \sprintf('Abort: %d stored_media_assets row(s) have status=ready with ready_at NULL.', $readyMissingReadyAt),
        );

        $nonReadyWithReadyAt = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM stored_media_assets
             WHERE status <> 'ready' AND ready_at IS NOT NULL
            SQL);
        $this->write(\sprintf('Preflight: non-ready with ready_at=%d', $nonReadyWithReadyAt));
        $this->abortIf(
            $nonReadyWithReadyAt > 0,
            \sprintf('Abort: %d stored_media_assets row(s) have ready_at set while status<>ready.', $nonReadyWithReadyAt),
        );

        $quarantinedMissingAt = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM stored_media_assets
             WHERE status = 'quarantined' AND quarantined_at IS NULL
            SQL);
        $this->write(\sprintf('Preflight: quarantined without quarantined_at=%d', $quarantinedMissingAt));
        $this->abortIf(
            $quarantinedMissingAt > 0,
            \sprintf('Abort: %d stored_media_assets row(s) have status=quarantined with quarantined_at NULL.', $quarantinedMissingAt),
        );

        $pendingReadyWithQuarantinedAt = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM stored_media_assets
             WHERE status IN ('pending', 'ready') AND quarantined_at IS NOT NULL
            SQL);
        $this->write(\sprintf('Preflight: pending/ready with quarantined_at=%d', $pendingReadyWithQuarantinedAt));
        $this->abortIf(
            $pendingReadyWithQuarantinedAt > 0,
            \sprintf(
                'Abort: %d stored_media_assets row(s) have quarantined_at set while status in (pending,ready).',
                $pendingReadyWithQuarantinedAt,
            ),
        );

        $archivedMissingAt = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM stored_media_assets
             WHERE status = 'archived' AND archived_at IS NULL
            SQL);
        $this->write(\sprintf('Preflight: archived without archived_at=%d', $archivedMissingAt));
        $this->abortIf(
            $archivedMissingAt > 0,
            \sprintf('Abort: %d stored_media_assets row(s) have status=archived with archived_at NULL.', $archivedMissingAt),
        );

        $nonArchivedWithArchivedAt = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM stored_media_assets
             WHERE status <> 'archived' AND archived_at IS NOT NULL
            SQL);
        $this->write(\sprintf('Preflight: non-archived with archived_at=%d', $nonArchivedWithArchivedAt));
        $this->abortIf(
            $nonArchivedWithArchivedAt > 0,
            \sprintf('Abort: %d stored_media_assets row(s) have archived_at set while status<>archived.', $nonArchivedWithArchivedAt),
        );

        $revisionsWithoutCurrent = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM learning_contents lc
             WHERE EXISTS (
                SELECT 1 FROM learning_content_revisions r WHERE r.content_id = lc.id
             )
             AND (lc.current_revision_id IS NULL OR lc.current_revision_number IS NULL)
            SQL);
        $this->write(\sprintf('Preflight: contents with revisions but null current=%d', $revisionsWithoutCurrent));
        $this->abortIf(
            $revisionsWithoutCurrent > 0,
            \sprintf(
                'Abort: %d learning_contents row(s) have revisions but current_revision_id/number is NULL.',
                $revisionsWithoutCurrent,
            ),
        );

        $currentNotLatest = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM learning_contents lc
             WHERE lc.current_revision_number IS NOT NULL
               AND lc.current_revision_number <> (
                    SELECT MAX(r.revision_number)
                      FROM learning_content_revisions r
                     WHERE r.content_id = lc.id
               )
            SQL);
        $this->write(\sprintf('Preflight: current_revision_number not MAX=%d', $currentNotLatest));
        $this->abortIf(
            $currentNotLatest > 0,
            \sprintf(
                'Abort: %d learning_contents row(s) have current_revision_number not equal to MAX(revision_number).',
                $currentNotLatest,
            ),
        );

        $publishedMismatch = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM learning_contents lc
             WHERE EXISTS (
                SELECT 1 FROM learning_content_publications p WHERE p.content_id = lc.id
             )
             AND NOT EXISTS (
                SELECT 1
                  FROM learning_content_publications p
                  INNER JOIN learning_content_revisions r ON r.id = p.revision_id
                 WHERE p.content_id = lc.id
                   AND p.publication_number = (
                        SELECT MAX(p2.publication_number)
                          FROM learning_content_publications p2
                         WHERE p2.content_id = lc.id
                   )
                   AND lc.published_revision_id = p.revision_id
                   AND lc.published_revision_number = r.revision_number
                   AND lc.published_at = p.published_at
             )
            SQL);
        $this->write(\sprintf('Preflight: published metadata mismatch vs latest publication=%d', $publishedMismatch));
        $this->abortIf(
            $publishedMismatch > 0,
            \sprintf(
                'Abort: %d learning_contents row(s) have published pointers/published_at not matching latest publication.',
                $publishedMismatch,
            ),
        );

        $this->addSql('ALTER TABLE stored_media_assets DROP CONSTRAINT chk_sma_ready_at');
        $this->addSql('ALTER TABLE stored_media_assets DROP CONSTRAINT chk_sma_quarantined_at');
        $this->addSql('ALTER TABLE stored_media_assets DROP CONSTRAINT chk_sma_archived_at');

        $this->addSql(<<<'SQL'
            ALTER TABLE stored_media_assets
                ADD CONSTRAINT chk_sma_ready_at CHECK (
                    (status = 'ready' AND ready_at IS NOT NULL)
                    OR (status <> 'ready' AND ready_at IS NULL)
                )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE stored_media_assets
                ADD CONSTRAINT chk_sma_quarantined_at CHECK (
                    (status = 'quarantined' AND quarantined_at IS NOT NULL)
                    OR (status IN ('pending', 'ready') AND quarantined_at IS NULL)
                    OR (status = 'archived')
                )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE stored_media_assets
                ADD CONSTRAINT chk_sma_archived_at CHECK (
                    (status = 'archived' AND archived_at IS NOT NULL)
                    OR (status <> 'archived' AND archived_at IS NULL)
                )
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_sma_bu_lifecycle');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_sma_bu_lifecycle BEFORE UPDATE ON stored_media_assets
            FOR EACH ROW
            BEGIN
                IF NEW.id <> OLD.id
                    OR NEW.scope <> OLD.scope
                    OR (NEW.institution_id IS NULL) <> (OLD.institution_id IS NULL)
                    OR (NEW.institution_id IS NOT NULL AND OLD.institution_id IS NOT NULL AND NEW.institution_id <> OLD.institution_id)
                    OR NEW.kind <> OLD.kind
                    OR NEW.storage_provider <> OLD.storage_provider
                    OR NEW.storage_key <> OLD.storage_key
                    OR NEW.content_sha256 <> OLD.content_sha256
                    OR NEW.byte_size <> OLD.byte_size
                    OR NEW.original_filename <> OLD.original_filename
                    OR NEW.mime_type <> OLD.mime_type
                    OR NEW.created_by_id <> OLD.created_by_id
                    OR NEW.created_at <> OLD.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_media_assets identity columns are immutable';
                END IF;

                IF NEW.updated_at < OLD.updated_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_media_assets updated_at cannot move backwards';
                END IF;

                IF NOT (
                    (OLD.status = 'pending' AND NEW.status IN ('pending', 'ready', 'quarantined'))
                    OR (OLD.status = 'ready' AND NEW.status IN ('ready', 'quarantined', 'archived'))
                    OR (OLD.status = 'quarantined' AND NEW.status IN ('quarantined', 'archived'))
                    OR (OLD.status = 'archived' AND NEW.status = 'archived')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_media_assets status transition rejected';
                END IF;

                IF NEW.status = 'ready' AND NEW.scan_status <> 'clean' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ready requires clean scan_status';
                END IF;

                IF NOT (
                    (OLD.scan_status = 'pending' AND NEW.scan_status IN ('pending', 'clean', 'infected', 'failed'))
                    OR (OLD.scan_status = 'clean' AND NEW.scan_status IN ('clean', 'infected', 'failed'))
                    OR (OLD.scan_status = 'infected' AND NEW.scan_status = 'infected')
                    OR (OLD.scan_status = 'failed' AND NEW.scan_status = 'failed')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_media_assets scan_status transition rejected';
                END IF;

                IF NEW.scan_status IN ('infected', 'failed') AND NEW.status = 'ready' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ready cannot have infected or failed scan';
                END IF;

                IF (NEW.status = 'ready' AND NEW.ready_at IS NULL)
                    OR (NEW.status <> 'ready' AND NEW.ready_at IS NOT NULL)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ready_at must match ready status';
                END IF;

                IF NEW.status = OLD.status
                    AND (
                        (NEW.ready_at IS NULL) <> (OLD.ready_at IS NULL)
                        OR (NEW.ready_at IS NOT NULL AND OLD.ready_at IS NOT NULL AND NEW.ready_at <> OLD.ready_at)
                    )
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ready_at cannot change without status transition';
                END IF;

                IF NEW.status IN ('pending', 'ready') AND NEW.quarantined_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quarantined_at forbidden for pending/ready';
                END IF;

                IF NEW.status = 'quarantined' AND NEW.quarantined_at IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quarantined_at required for quarantined';
                END IF;

                IF NEW.status = 'archived' THEN
                    IF OLD.status IN ('pending', 'ready') AND NEW.quarantined_at IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'archived from pending/ready cannot invent quarantined_at';
                    END IF;
                    IF OLD.status = 'quarantined'
                        AND NEW.quarantined_at IS NOT NULL
                        AND (OLD.quarantined_at IS NULL OR NEW.quarantined_at <> OLD.quarantined_at)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'archived may only keep or clear prior quarantined_at';
                    END IF;
                    IF OLD.status = 'archived'
                        AND (
                            (NEW.quarantined_at IS NULL) <> (OLD.quarantined_at IS NULL)
                            OR (NEW.quarantined_at IS NOT NULL AND OLD.quarantined_at IS NOT NULL AND NEW.quarantined_at <> OLD.quarantined_at)
                        )
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quarantined_at immutable while archived';
                    END IF;
                END IF;

                IF NEW.status = OLD.status
                    AND NEW.status = 'quarantined'
                    AND (
                        (NEW.quarantined_at IS NULL) <> (OLD.quarantined_at IS NULL)
                        OR (NEW.quarantined_at IS NOT NULL AND OLD.quarantined_at IS NOT NULL AND NEW.quarantined_at <> OLD.quarantined_at)
                    )
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quarantined_at cannot change without status transition';
                END IF;

                IF (NEW.status = 'archived' AND NEW.archived_at IS NULL)
                    OR (NEW.status <> 'archived' AND NEW.archived_at IS NOT NULL)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'archived_at must match archived status';
                END IF;

                IF NEW.status = OLD.status
                    AND (
                        (NEW.archived_at IS NULL) <> (OLD.archived_at IS NULL)
                        OR (NEW.archived_at IS NOT NULL AND OLD.archived_at IS NOT NULL AND NEW.archived_at <> OLD.archived_at)
                    )
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'archived_at cannot change without status transition';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_lcr_bi_sequential_current');
        $this->addSql('DROP TRIGGER IF EXISTS trg_lcr_bi_revision_number');
        $this->addSql('DROP TRIGGER IF EXISTS trg_lcr_ai_sync_current');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcr_bi_revision_number BEFORE INSERT ON learning_content_revisions
            FOR EACH ROW
            BEGIN
                DECLARE max_num INT;

                SELECT COALESCE(MAX(r.revision_number), 0)
                  INTO max_num
                  FROM learning_content_revisions r
                 WHERE r.content_id = NEW.content_id;

                IF NEW.revision_number <> (max_num + 1) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'revision_number must be next sequential value';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcr_ai_sync_current
            AFTER INSERT ON learning_content_revisions
            FOR EACH ROW
            BEGIN
                UPDATE learning_contents lc
                   SET lc.current_revision_id = NEW.id,
                       lc.current_revision_number = NEW.revision_number,
                       lc.updated_at = NEW.created_at
                 WHERE lc.id = NEW.content_id;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_lc_bu_published_matches_latest');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lc_bu_published_matches_latest
            BEFORE UPDATE ON learning_contents
            FOR EACH ROW
            BEGIN
                DECLARE latest_revision_id BINARY(16);
                DECLARE latest_revision_number INT;
                DECLARE latest_published_at DATETIME;
                DECLARE latest_current_id BINARY(16);
                DECLARE latest_current_number INT;
                DECLARE publication_count INT;
                DECLARE revision_count INT;

                IF OLD.status = 'archived' AND NEW.status <> 'archived' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot un-archive learning content';
                END IF;

                SELECT COUNT(*)
                  INTO revision_count
                  FROM learning_content_revisions r
                 WHERE r.content_id = NEW.id;

                IF revision_count > 0 THEN
                    IF NEW.current_revision_id IS NULL OR NEW.current_revision_number IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'cannot clear current revision while revisions exist';
                    END IF;

                    SELECT r.id, r.revision_number
                      INTO latest_current_id, latest_current_number
                      FROM learning_content_revisions r
                     WHERE r.content_id = NEW.id
                     ORDER BY r.revision_number DESC
                     LIMIT 1;

                    IF NEW.current_revision_id <> latest_current_id
                       OR NEW.current_revision_number <> latest_current_number THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'current revision must equal latest revision';
                    END IF;
                END IF;

                SELECT COUNT(*)
                  INTO publication_count
                  FROM learning_content_publications p
                 WHERE p.content_id = NEW.id;

                IF publication_count > 0 THEN
                    SELECT p.revision_id, r.revision_number, p.published_at
                      INTO latest_revision_id, latest_revision_number, latest_published_at
                      FROM learning_content_publications p
                      INNER JOIN learning_content_revisions r ON r.id = p.revision_id
                     WHERE p.content_id = NEW.id
                     ORDER BY p.publication_number DESC
                     LIMIT 1;

                    IF latest_revision_id IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'published revision requires learning_content_publication';
                    END IF;

                    IF NEW.published_revision_id IS NULL
                       OR NEW.published_revision_number IS NULL
                       OR NEW.published_at IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'cannot clear published pointers or published_at while publications exist';
                    END IF;

                    IF NEW.published_revision_id <> latest_revision_id
                       OR NEW.published_revision_number <> latest_revision_number
                       OR NEW.published_at <> latest_published_at THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'published pointer must match latest publication';
                    END IF;
                ELSEIF NEW.published_revision_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'published revision requires learning_content_publication';
                END IF;

                IF NEW.status = 'published' THEN
                    IF NEW.published_revision_id IS NULL
                       OR NEW.published_revision_number IS NULL
                       OR NEW.published_at IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'published status requires published pointers and published_at';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260912150000 is an irreversible security migration; '
            .'downgrading would restore weaker media timestamp and revision/current integrity.',
        );
    }
}
