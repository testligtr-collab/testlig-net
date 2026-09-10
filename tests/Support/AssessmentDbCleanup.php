<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for assessment blueprint fixtures.
 *
 * Does not bypass MariaDB append-only DELETE triggers and does not NULL published
 * pointers while publications exist. Immutable child rows are removed only via
 * ON DELETE CASCADE from parent `assessments` (MariaDB does not fire child DELETE
 * triggers for FK cascading actions). Pointer FKs use ON DELETE CASCADE so the
 * assessment↔revision cycle resolves without a detach UPDATE.
 */
final class AssessmentDbCleanup
{
    public static function deleteAssessments(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['assessments'])) {
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
