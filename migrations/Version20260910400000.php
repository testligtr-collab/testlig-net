<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.9 integrity: close publication-pointer detach hole; enable safe parent Assessment DELETE.
 *
 * Irreversible security migration — down() does not restore the detach exception.
 *
 * Does not modify Version20260910120000, Version20260910200000, or Version20260910300000.
 */
final class Version20260910400000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reject clearing published pointers while publications exist; CASCADE assessment→revision pointer FKs for parent DELETE';
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
                'Cannot harden publication-pointer detach rules: %d orphan publication(s) '
                .'(assessments=%d, publications=%d).',
                $orphanPublications,
                $assessmentCount,
                $publicationCount,
            ),
        );

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
                'Cannot harden publication-pointer detach rules: %d assessment(s) have inconsistent '
                .'published pointer vs publications (assessments=%d, publications=%d). '
                .'Backfill or repair before migrating; no silent delete.',
                $inconsistent,
                $assessmentCount,
                $publicationCount,
            ),
        );

        // Parent Assessment DELETE must resolve the assessment↔revision pointer cycle without a
        // mid-transaction UPDATE that clears published pointers while publications still exist.
        // ON DELETE CASCADE on pointer FKs is safe because revision rows are append-only
        // (BEFORE DELETE SIGNAL); the only path that removes revisions is FK cascade from
        // assessments, and MariaDB does not fire child DELETE triggers for cascading actions.
        $this->addSql('ALTER TABLE assessments DROP FOREIGN KEY FK_ASSESSMENT_CURRENT_REVISION');
        $this->addSql('ALTER TABLE assessments DROP FOREIGN KEY FK_ASSESSMENT_PUBLISHED_REVISION');
        $this->addSql('ALTER TABLE assessments DROP FOREIGN KEY FK_4BFCEC0AA32ED756');
        $this->addSql('ALTER TABLE assessments DROP FOREIGN KEY FK_4BFCEC0AFE671D30');

        $this->addSql(
            'ALTER TABLE assessments ADD CONSTRAINT FK_4BFCEC0AA32ED756 FOREIGN KEY (current_revision_id) REFERENCES assessment_revisions (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE assessments ADD CONSTRAINT FK_4BFCEC0AFE671D30 FOREIGN KEY (published_revision_id) REFERENCES assessment_revisions (id) ON DELETE CASCADE',
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD CONSTRAINT FK_ASSESSMENT_CURRENT_REVISION
                FOREIGN KEY (current_revision_id, id, current_revision_number)
                REFERENCES assessment_revisions (id, assessment_id, revision_number)
                ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD CONSTRAINT FK_ASSESSMENT_PUBLISHED_REVISION
                FOREIGN KEY (published_revision_id, id, published_revision_number)
                REFERENCES assessment_revisions (id, assessment_id, revision_number)
                ON DELETE CASCADE
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessments_bu_published_matches_latest_publication');
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
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'cannot clear published pointer while publications exist';
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
            'Version20260910400000 is an irreversible security migration; '
            .'downgrading would restore the publication-pointer detach hole.',
        );
    }
}
