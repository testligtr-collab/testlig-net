<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.9 integrity: composite current/published revision pointers, seal BI, publication BI.
 *
 * Irreversible security migration — down() does not restore the weaker pre-pointer state.
 *
 * Does not modify Version20260910120000.
 */
final class Version20260910200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce assessment revision pointers, sealed insert BI, and publication current-revision BI';
    }

    public function up(Schema $schema): void
    {
        $existingAssessments = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM assessments');
        $badCurrentNumber = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessments
             WHERE current_revision_number IS NOT NULL AND current_revision_number < 1',
        );
        $badPublishedNumber = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessments
             WHERE published_revision_number IS NOT NULL AND published_revision_number < 1',
        );
        $this->abortIf(
            $badCurrentNumber > 0 || $badPublishedNumber > 0,
            \sprintf(
                'Cannot add assessment pointer CHECKs: %d assessments exist; %d bad current_revision_number, %d bad published_revision_number.',
                $existingAssessments,
                $badCurrentNumber,
                $badPublishedNumber,
            ),
        );

        // Apply pointer columns + backfill immediately so leftover orphans can abort before CHECKs.
        $this->connection->executeStatement('ALTER TABLE assessments MODIFY current_revision_number INT DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE assessments ADD current_revision_id BINARY(16) DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE assessments ADD published_revision_id BINARY(16) DEFAULT NULL');
        $this->connection->executeStatement(<<<'SQL'
            UPDATE assessments a
            INNER JOIN assessment_revisions r
                ON r.assessment_id = a.id AND r.revision_number = a.current_revision_number
            SET a.current_revision_id = r.id
            WHERE a.current_revision_number IS NOT NULL
              AND a.current_revision_id IS NULL
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            UPDATE assessments a
            INNER JOIN assessment_revisions r
                ON r.assessment_id = a.id AND r.revision_number = a.published_revision_number
            SET a.published_revision_id = r.id
            WHERE a.published_revision_number IS NOT NULL
              AND a.published_revision_id IS NULL
            SQL);

        $orphanCurrent = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessments
             WHERE (current_revision_id IS NULL) <> (current_revision_number IS NULL)',
        );
        $orphanPublished = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessments
             WHERE (published_revision_id IS NULL) <> (published_revision_number IS NULL)',
        );
        $this->abortIf(
            $orphanCurrent > 0 || $orphanPublished > 0,
            \sprintf(
                'Assessment pointer backfill left orphans (existing assessments=%d, current=%d, published=%d). No silent delete.',
                $existingAssessments,
                $orphanCurrent,
                $orphanPublished,
            ),
        );

        // Drop legacy number-only CHECKs that conflict with nullable current pointers.
        $this->addSql('ALTER TABLE assessments DROP CONSTRAINT chk_assessment_current_revision');
        $this->addSql('ALTER TABLE assessments DROP CONSTRAINT chk_assessment_published_revision');

        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD CONSTRAINT chk_assessment_current_pointer CHECK (
                    (current_revision_id IS NULL AND current_revision_number IS NULL)
                    OR (current_revision_id IS NOT NULL AND current_revision_number IS NOT NULL)
                )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD CONSTRAINT chk_assessment_published_pointer CHECK (
                    (published_revision_id IS NULL AND published_revision_number IS NULL)
                    OR (published_revision_id IS NOT NULL AND published_revision_number IS NOT NULL)
                )
            SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_ar_id_assessment_number ON assessment_revisions (id, assessment_id, revision_number)',
        );

        // Create Doctrine IDX_* supporting indexes BEFORE FKs so MariaDB does not invent FK-named keys.
        $this->addSql('CREATE INDEX IDX_4BFCEC0AA32ED756 ON assessments (current_revision_id)');
        $this->addSql('CREATE INDEX IDX_4BFCEC0AFE671D30 ON assessments (published_revision_id)');
        $this->addSql('CREATE INDEX IDX_4BFCEC0AA32ED756BF39675067276641 ON assessments (current_revision_id, id, current_revision_number)');
        $this->addSql('CREATE INDEX IDX_4BFCEC0AFE671D30BF396750D133040D ON assessments (published_revision_id, id, published_revision_number)');

        $this->addSql(
            'ALTER TABLE assessments ADD CONSTRAINT FK_4BFCEC0AA32ED756 FOREIGN KEY (current_revision_id) REFERENCES assessment_revisions (id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'ALTER TABLE assessments ADD CONSTRAINT FK_4BFCEC0AFE671D30 FOREIGN KEY (published_revision_id) REFERENCES assessment_revisions (id) ON DELETE RESTRICT',
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD CONSTRAINT FK_ASSESSMENT_CURRENT_REVISION
                FOREIGN KEY (current_revision_id, id, current_revision_number)
                REFERENCES assessment_revisions (id, assessment_id, revision_number)
                ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE assessments
                ADD CONSTRAINT FK_ASSESSMENT_PUBLISHED_REVISION
                FOREIGN KEY (published_revision_id, id, published_revision_number)
                REFERENCES assessment_revisions (id, assessment_id, revision_number)
                ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_revisions_bi BEFORE INSERT ON assessment_revisions
            FOR EACH ROW
            BEGIN
                IF NEW.is_sealed <> 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_revisions must insert unsealed';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_publications_bi BEFORE INSERT ON assessment_publications
            FOR EACH ROW
            BEGIN
                DECLARE rev_assessment_id BINARY(16);
                DECLARE rev_is_sealed TINYINT(1);
                DECLARE assessment_current_revision_id BINARY(16);

                SELECT ar.assessment_id, ar.is_sealed
                  INTO rev_assessment_id, rev_is_sealed
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
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessments_bu_published_requires_publication BEFORE UPDATE ON assessments
            FOR EACH ROW
            BEGIN
                IF NEW.published_revision_id IS NOT NULL
                   AND (
                        OLD.published_revision_id IS NULL
                        OR NEW.published_revision_id <> OLD.published_revision_id
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM assessment_publications ap
                         WHERE ap.assessment_id = NEW.id
                           AND ap.assessment_revision_id = NEW.published_revision_id
                   ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'published revision requires assessment_publication';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Version20260910200000 is an irreversible security migration; '
            .'downgrading would restore insecure sealed-insert / pointer semantics.',
        );
    }
}
