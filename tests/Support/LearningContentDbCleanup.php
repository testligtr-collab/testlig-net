<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for learning content + stored media fixtures.
 *
 * Does not bypass MariaDB append-only DELETE triggers. Immutable child rows are
 * removed only via ON DELETE CASCADE from parent `learning_contents` (MariaDB does
 * not fire child DELETE triggers for FK cascading actions). Published pointers are
 * nulled first so RESTRICT pointer FKs do not block parent delete.
 */
final class LearningContentDbCleanup
{
    public static function deleteLearningContents(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();

        if ($schema->tablesExist(['learning_contents'])) {
            $connection->executeStatement(
                'UPDATE learning_contents
                 SET published_revision_id = NULL,
                     published_revision_number = NULL,
                     published_at = NULL,
                     archived_at = NULL,
                     status = \'draft\'
                 WHERE published_revision_id IS NOT NULL OR status IN (\'published\', \'archived\')',
            );
            $connection->executeStatement('DELETE FROM learning_contents');
        }
        if ($schema->tablesExist(['stored_media_assets'])) {
            $connection->executeStatement('DELETE FROM stored_media_assets');
        }

        self::assertEmpty($connection);
    }

    public static function assertEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach ([
            'learning_content_revision_assets',
            'learning_content_revision_primary_alignment_guards',
            'learning_content_outcome_alignments',
            'learning_content_publications',
            'learning_content_revisions',
            'learning_contents',
            'stored_media_assets',
        ] as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            Assert::assertSame(0, $count, \sprintf('Expected %s empty after learning content cleanup.', $table));
        }
    }
}
