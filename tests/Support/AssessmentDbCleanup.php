<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for assessment blueprint fixtures.
 *
 * Does not bypass MariaDB append-only DELETE triggers. Immutable child rows are
 * removed only via ON DELETE CASCADE from parent `assessments`.
 */
final class AssessmentDbCleanup
{
    public static function deleteAssessments(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['assessments'])) {
            // Break circular assessment↔revision pointer FKs before DELETE.
            // Clearing published alone is rejected while publications exist; clear current+published together.
            if ($schema->introspectTable('assessments')->hasColumn('current_revision_id')) {
                $connection->executeStatement(
                    'UPDATE assessments SET
                        published_revision_id = NULL,
                        published_revision_number = NULL,
                        current_revision_id = NULL,
                        current_revision_number = NULL',
                );
            }
            // MariaDB FK CASCADE delete of children does not fire append-only DELETE triggers.
            $connection->executeStatement('DELETE FROM assessments');
        }
        self::assertAssessmentTablesEmpty($connection);
    }

    public static function assertAssessmentTablesEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach ([
            'assessment_publications',
            'assessment_items',
            'assessment_sections',
            'assessment_revisions',
            'assessments',
        ] as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            Assert::assertSame(0, $count, \sprintf('Expected %s empty after cascade cleanup.', $table));
        }
    }
}
