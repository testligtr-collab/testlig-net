<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.9 integrity: bidirectional publication ↔ published-pointer sync + monotonic publication numbers.
 *
 * Irreversible security migration — down() does not restore one-way pointer checks.
 *
 * Does not modify Version20260910120000 or Version20260910200000.
 */
final class Version20260910300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync published assessment pointers from publication INSERT; enforce sequential publication numbers and latest-publication pointer match';
    }

    public function up(Schema $schema): void
    {
        $assessmentCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessments');
        $publicationCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessment_publications');

        $orphanPublications = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessment_publications ap
            LEFT JOIN assessments a ON a.id = ap.assessment_id
            WHERE a.id IS NULL
            SQL);
        $this->abortIf(
            $orphanPublications > 0,
            \sprintf(
                'Cannot harden publication pointers: %d orphan publication(s) (assessments=%d, publications=%d).',
                $orphanPublications,
                $assessmentCount,
                $publicationCount,
            ),
        );

        // Pointer set but no matching latest publication, or publications exist with mismatched/null pointer.
        $inconsistent = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM assessments a
            WHERE (
                a.published_revision_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM assessment_publications ap
                    WHERE ap.assessment_id = a.id
                      AND ap.assessment_revision_id = a.published_revision_id
                )
            )
            OR (
                EXISTS (SELECT 1 FROM assessment_publications ap WHERE ap.assessment_id = a.id)
                AND (
                    a.published_revision_id IS NULL
                    OR a.published_revision_id <> (
                        SELECT ap2.assessment_revision_id
                          FROM assessment_publications ap2
                         WHERE ap2.assessment_id = a.id
                         ORDER BY ap2.publication_number DESC
                         LIMIT 1
                    )
                )
            )
            SQL);
        $this->abortIf(
            $inconsistent > 0,
            \sprintf(
                'Cannot harden publication pointers: %d assessment(s) have inconsistent published pointer vs publications '
                .'(assessments=%d, publications=%d). Backfill or repair before migrating; no silent delete.',
                $inconsistent,
                $assessmentCount,
                $publicationCount,
            ),
        );

        // Gaps / non-monotonic publication numbers within an assessment.
        $nonSequential = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT ap.assessment_id
                  FROM assessment_publications ap
                 GROUP BY ap.assessment_id
                HAVING MIN(ap.publication_number) <> 1
                    OR MAX(ap.publication_number) <> COUNT(*)
                    OR COUNT(DISTINCT ap.publication_number) <> COUNT(*)
            ) bad
            SQL);
        $this->abortIf(
            $nonSequential > 0,
            \sprintf(
                'Cannot enforce sequential publication numbers: %d assessment(s) have non-sequential publication_number '
                .'(assessments=%d, publications=%d).',
                $nonSequential,
                $assessmentCount,
                $publicationCount,
            ),
        );

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_publications_bi');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_publications_ai_sync_published');
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessments_bu_published_requires_publication');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_publications_bi BEFORE INSERT ON assessment_publications
            FOR EACH ROW
            BEGIN
                DECLARE rev_assessment_id BINARY(16);
                DECLARE rev_is_sealed TINYINT(1);
                DECLARE rev_number INT;
                DECLARE assessment_current_revision_id BINARY(16);
                DECLARE max_publication_number INT;

                SELECT ar.assessment_id, ar.is_sealed, ar.revision_number
                  INTO rev_assessment_id, rev_is_sealed, rev_number
                  FROM assessment_revisions ar
                 WHERE ar.id = NEW.assessment_revision_id
                 LIMIT 1;

                IF rev_assessment_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication revision not found';
                END IF;

                IF rev_assessment_id <> NEW.assessment_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication revision assessment mismatch';
                END IF;

                IF rev_is_sealed <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication requires sealed revision';
                END IF;

                SELECT a.current_revision_id
                  INTO assessment_current_revision_id
                  FROM assessments a
                 WHERE a.id = NEW.assessment_id
                 LIMIT 1;

                IF assessment_current_revision_id IS NULL
                   OR assessment_current_revision_id <> NEW.assessment_revision_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication must target current revision';
                END IF;

                IF NOT (
                    CHAR_LENGTH(NEW.manifest_hash) = 64
                    AND NEW.manifest_hash REGEXP BINARY '^[0-9a-f]{64}$'
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication manifest_hash invalid';
                END IF;

                SELECT COALESCE(MAX(ap.publication_number), 0)
                  INTO max_publication_number
                  FROM assessment_publications ap
                 WHERE ap.assessment_id = NEW.assessment_id;

                IF NEW.publication_number <> (max_publication_number + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication_number must be next sequential value';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_publications_ai_sync_published
            AFTER INSERT ON assessment_publications
            FOR EACH ROW
            BEGIN
                DECLARE rev_number INT;

                SELECT ar.revision_number
                  INTO rev_number
                  FROM assessment_revisions ar
                 WHERE ar.id = NEW.assessment_revision_id
                 LIMIT 1;

                IF rev_number IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication sync revision not found';
                END IF;

                UPDATE assessments a
                   SET a.published_revision_id = NEW.assessment_revision_id,
                       a.published_revision_number = rev_number,
                       a.status = 'published',
                       a.updated_at = NEW.published_at
                 WHERE a.id = NEW.assessment_id;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessments_bu_published_matches_latest_publication
            BEFORE UPDATE ON assessments
            FOR EACH ROW
            BEGIN
                DECLARE latest_revision_id BINARY(16);
                DECLARE latest_revision_number INT;
                DECLARE publication_count INT;

                SELECT COUNT(*)
                  INTO publication_count
                  FROM assessment_publications ap
                 WHERE ap.assessment_id = NEW.id;

                IF NEW.published_revision_id IS NULL AND publication_count > 0 THEN
                    -- Allow full detach (current+published cleared together) for parent DELETE cleanup.
                    -- Mid-TX inconsistency is closed by cascading assessment DELETE of publications.
                    IF NEW.current_revision_id IS NOT NULL OR NEW.current_revision_number IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'cannot clear published pointer while publications exist';
                    END IF;
                END IF;

                IF NEW.published_revision_id IS NOT NULL THEN
                    SELECT ap.assessment_revision_id, ar.revision_number
                      INTO latest_revision_id, latest_revision_number
                      FROM assessment_publications ap
                      INNER JOIN assessment_revisions ar ON ar.id = ap.assessment_revision_id
                     WHERE ap.assessment_id = NEW.id
                     ORDER BY ap.publication_number DESC
                     LIMIT 1;

                    IF latest_revision_id IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'published revision requires assessment_publication';
                    END IF;

                    IF NEW.published_revision_id <> latest_revision_id
                       OR NEW.published_revision_number IS NULL
                       OR NEW.published_revision_number <> latest_revision_number THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'published pointer must match latest publication';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260910300000 is an irreversible security migration; '
            .'downgrading would restore one-way publication/pointer integrity.',
        );
    }
}
