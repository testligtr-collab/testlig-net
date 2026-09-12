<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.15 integrity: current revision pointer, publication BI/AI/BU sync, asset tenant + lifecycle.
 *
 * Irreversible security migration — down() does not restore weaker triggers/pointer model.
 *
 * Does not modify Version20260912120000 or Version20260912130000.
 *
 * Pointer FKs use ON DELETE CASCADE (Assessment Stage 2.9 pattern) so parent LearningContent
 * DELETE resolves the content↔revision cycle without clearing published pointers while
 * publications still exist. Revision/publication rows remain append-only via BEFORE DELETE SIGNAL;
 * MariaDB does not fire those triggers for FK cascading actions.
 */
final class Version20260912140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden learning content current revision pointers, publication sync triggers, asset tenant and media lifecycle';
    }

    public function up(Schema $schema): void
    {
        $contentCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM learning_contents');
        $revisionCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM learning_content_revisions');
        $publicationCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM learning_content_publications');
        $revisionAssetCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM learning_content_revision_assets');
        $mediaCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM stored_media_assets');

        $this->write(\sprintf(
            'Preflight counts: learning_contents=%d revisions=%d publications=%d revision_assets=%d stored_media_assets=%d',
            $contentCount,
            $revisionCount,
            $publicationCount,
            $revisionAssetCount,
            $mediaCount,
        ));

        $orphanCurrent = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM learning_contents lc
            WHERE NOT EXISTS (
                SELECT 1 FROM learning_content_revisions r
                 WHERE r.content_id = lc.id
                   AND r.revision_number = lc.current_revision_number
            )
            SQL);
        $this->abortIf(
            $orphanCurrent > 0,
            \sprintf(
                'Cannot harden current revision pointers: %d learning_contents row(s) have current_revision_number '
                .'with no matching learning_content_revisions row (contents=%d, revisions=%d).',
                $orphanCurrent,
                $contentCount,
                $revisionCount,
            ),
        );

        // Apply pointer column + backfill immediately so leftover orphans abort before CHECKs/FKs.
        $this->connection->executeStatement('ALTER TABLE learning_contents ADD current_revision_id BINARY(16) DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE learning_contents DROP CONSTRAINT chk_lc_current_revision');
        $this->connection->executeStatement('ALTER TABLE learning_contents MODIFY current_revision_number INT DEFAULT NULL');
        $this->connection->executeStatement(<<<'SQL'
            UPDATE learning_contents lc
            INNER JOIN learning_content_revisions r
                ON r.content_id = lc.id AND r.revision_number = lc.current_revision_number
            SET lc.current_revision_id = r.id
            SQL);

        $nullCurrent = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM learning_contents
             WHERE current_revision_id IS NULL OR current_revision_number IS NULL
            SQL);
        $this->abortIf(
            $nullCurrent > 0,
            \sprintf(
                'Current revision backfill left NULL pointers on %d learning_contents row(s) '
                .'(contents=%d, revisions=%d). No silent delete.',
                $nullCurrent,
                $contentCount,
                $revisionCount,
            ),
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE learning_contents
                ADD CONSTRAINT chk_lc_current_revision_pair CHECK (
                    (current_revision_id IS NULL AND current_revision_number IS NULL)
                    OR (
                        current_revision_id IS NOT NULL
                        AND current_revision_number IS NOT NULL
                        AND current_revision_number >= 1
                    )
                )
            SQL);

        // Doctrine IDX_* supporting indexes BEFORE FKs so MariaDB does not invent FK-named keys.
        $this->addSql('CREATE INDEX IDX_908A1569A32ED756 ON learning_contents (current_revision_id)');
        $this->addSql('CREATE INDEX IDX_908A1569A32ED756BF39675067276641 ON learning_contents (current_revision_id, id, current_revision_number)');

        // Recreate published pointer FKs as CASCADE (Assessment 2.9 parent-DELETE cycle).
        $this->addSql('ALTER TABLE learning_contents DROP FOREIGN KEY FK_LC_PUBLISHED_REVISION');
        $this->addSql('ALTER TABLE learning_contents DROP FOREIGN KEY FK_LC_PUBLISHED_REVISION_CONTENT');
        $this->addSql(
            'ALTER TABLE learning_contents ADD CONSTRAINT FK_908A1569FE671D30 FOREIGN KEY (published_revision_id) REFERENCES learning_content_revisions (id) ON DELETE CASCADE',
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE learning_contents
                ADD CONSTRAINT FK_LC_PUBLISHED_REVISION_CONTENT
                FOREIGN KEY (published_revision_id, id, published_revision_number)
                REFERENCES learning_content_revisions (id, content_id, revision_number)
                ON DELETE CASCADE
            SQL);

        $this->addSql(
            'ALTER TABLE learning_contents ADD CONSTRAINT FK_908A1569A32ED756 FOREIGN KEY (current_revision_id) REFERENCES learning_content_revisions (id) ON DELETE CASCADE',
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE learning_contents
                ADD CONSTRAINT FK_LC_CURRENT_REVISION_CONTENT
                FOREIGN KEY (current_revision_id, id, current_revision_number)
                REFERENCES learning_content_revisions (id, content_id, revision_number)
                ON DELETE CASCADE
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_lcp_bi');
        $this->addSql('DROP TRIGGER IF EXISTS trg_lcp_ai_sync_published');
        $this->addSql('DROP TRIGGER IF EXISTS trg_lc_bu_published_matches_latest');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcp_bi BEFORE INSERT ON learning_content_publications
            FOR EACH ROW
            BEGIN
                DECLARE rev_content_id BINARY(16);
                DECLARE rev_is_sealed TINYINT(1);
                DECLARE rev_content_hash VARCHAR(64);
                DECLARE rev_schema_version INT;
                DECLARE content_current_revision_id BINARY(16);
                DECLARE max_publication_number INT;

                SELECT r.content_id, r.is_sealed, r.content_hash, r.schema_version
                  INTO rev_content_id, rev_is_sealed, rev_content_hash, rev_schema_version
                  FROM learning_content_revisions r
                 WHERE r.id = NEW.revision_id
                 LIMIT 1;

                IF rev_content_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication revision not found';
                END IF;

                IF rev_content_id <> NEW.content_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication revision content mismatch';
                END IF;

                IF rev_is_sealed <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication requires sealed revision';
                END IF;

                SELECT lc.current_revision_id
                  INTO content_current_revision_id
                  FROM learning_contents lc
                 WHERE lc.id = NEW.content_id
                 LIMIT 1;

                IF content_current_revision_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication content not found';
                END IF;

                IF content_current_revision_id <> NEW.revision_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication must target current revision';
                END IF;

                IF NOT (NEW.content_hash = BINARY rev_content_hash) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication content_hash mismatch';
                END IF;

                IF NEW.schema_version <> rev_schema_version THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication schema_version mismatch';
                END IF;

                IF NOT (
                    CHAR_LENGTH(NEW.content_hash) = 64
                    AND NEW.content_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication content_hash invalid';
                END IF;

                SELECT COALESCE(MAX(p.publication_number), 0)
                  INTO max_publication_number
                  FROM learning_content_publications p
                 WHERE p.content_id = NEW.content_id;

                IF NEW.publication_number <> (max_publication_number + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication_number must be next sequential value';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcp_ai_sync_published
            AFTER INSERT ON learning_content_publications
            FOR EACH ROW
            BEGIN
                DECLARE rev_number INT;

                SELECT r.revision_number
                  INTO rev_number
                  FROM learning_content_revisions r
                 WHERE r.id = NEW.revision_id
                 LIMIT 1;

                IF rev_number IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication sync revision not found';
                END IF;

                UPDATE learning_contents lc
                   SET lc.published_revision_id = NEW.revision_id,
                       lc.published_revision_number = rev_number,
                       lc.status = 'published',
                       lc.published_at = NEW.published_at,
                       lc.updated_at = NEW.published_at,
                       lc.archived_at = NULL
                 WHERE lc.id = NEW.content_id;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lc_bu_published_matches_latest
            BEFORE UPDATE ON learning_contents
            FOR EACH ROW
            BEGIN
                DECLARE latest_revision_id BINARY(16);
                DECLARE latest_revision_number INT;
                DECLARE publication_count INT;
                DECLARE revision_count INT;

                IF OLD.status = 'archived' AND NEW.status <> 'archived' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot un-archive learning content';
                END IF;

                SELECT COUNT(*)
                  INTO revision_count
                  FROM learning_content_revisions r
                 WHERE r.content_id = NEW.id;

                -- Allow mid-create null current (revision INSERT then assign in same TX).
                -- Only reject clearing a previously assigned current pointer.
                IF (NEW.current_revision_id IS NULL OR NEW.current_revision_number IS NULL)
                   AND revision_count > 0
                   AND (OLD.current_revision_id IS NOT NULL OR OLD.current_revision_number IS NOT NULL) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'cannot clear current revision while revisions exist';
                END IF;

                SELECT COUNT(*)
                  INTO publication_count
                  FROM learning_content_publications p
                 WHERE p.content_id = NEW.id;

                IF NEW.published_revision_id IS NULL AND publication_count > 0 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'cannot clear published pointer while publications exist';
                END IF;

                IF NEW.published_revision_id IS NOT NULL THEN
                    SELECT p.revision_id, r.revision_number
                      INTO latest_revision_id, latest_revision_number
                      FROM learning_content_publications p
                      INNER JOIN learning_content_revisions r ON r.id = p.revision_id
                     WHERE p.content_id = NEW.id
                     ORDER BY p.publication_number DESC
                     LIMIT 1;

                    IF latest_revision_id IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'published revision requires learning_content_publication';
                    END IF;

                    IF NEW.published_revision_id <> latest_revision_id
                       OR NEW.published_revision_number IS NULL
                       OR NEW.published_revision_number <> latest_revision_number THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'published pointer must match latest publication';
                    END IF;
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

        $this->addSql('DROP TRIGGER IF EXISTS trg_lcra_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_lcra_bi BEFORE INSERT ON learning_content_revision_assets
            FOR EACH ROW
            BEGIN
                DECLARE rev_is_sealed TINYINT(1);
                DECLARE content_scope VARCHAR(32);
                DECLARE content_institution_id BINARY(16);
                DECLARE asset_scope VARCHAR(32);
                DECLARE asset_institution_id BINARY(16);
                DECLARE asset_status VARCHAR(32);

                SELECT r.is_sealed, lc.scope, lc.institution_id
                  INTO rev_is_sealed, content_scope, content_institution_id
                  FROM learning_content_revisions r
                  INNER JOIN learning_contents lc ON lc.id = r.content_id
                 WHERE r.id = NEW.revision_id
                 LIMIT 1;

                IF rev_is_sealed IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revision asset revision not found';
                END IF;

                IF rev_is_sealed = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot attach asset to sealed revision';
                END IF;

                SELECT a.scope, a.institution_id, a.status
                  INTO asset_scope, asset_institution_id, asset_status
                  FROM stored_media_assets a
                 WHERE a.id = NEW.asset_id
                 LIMIT 1;

                IF asset_scope IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revision asset media not found';
                END IF;

                IF asset_status = 'archived' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot attach archived media asset';
                END IF;

                IF content_scope = 'platform' AND asset_scope <> 'platform' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'platform content cannot use institution media';
                END IF;

                IF content_scope = 'institution' THEN
                    IF asset_scope = 'institution'
                        AND (
                            content_institution_id IS NULL
                            OR asset_institution_id IS NULL
                            OR content_institution_id <> asset_institution_id
                        ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revision asset tenant mismatch';
                    END IF;
                    IF asset_scope NOT IN ('platform', 'institution') THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revision asset tenant mismatch';
                    END IF;
                END IF;
            END
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

                IF (NEW.status = 'quarantined' AND NEW.quarantined_at IS NULL)
                    OR (NEW.status <> 'quarantined' AND NEW.quarantined_at IS NOT NULL AND NEW.status <> 'archived')
                THEN
                    IF NEW.status = 'quarantined' AND NEW.quarantined_at IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quarantined_at required for quarantined';
                    END IF;
                END IF;

                IF (NEW.status = 'archived' AND NEW.archived_at IS NULL)
                    OR (NEW.status <> 'archived' AND NEW.archived_at IS NOT NULL)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'archived_at must match archived status';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260912140000 is an irreversible security migration; '
            .'downgrading would restore weaker publication/pointer and media lifecycle integrity.',
        );
    }
}
