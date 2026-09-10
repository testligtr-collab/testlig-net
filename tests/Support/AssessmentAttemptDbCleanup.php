<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for assessment attempt fixtures.
 *
 * Must run before delivery/assessment cleanup (attempts RESTRICT on delivery).
 * Does not use FOREIGN_KEY_CHECKS or session-variable bypasses.
 */
final class AssessmentAttemptDbCleanup
{
    public static function deleteAttempts(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach ([
            'assessment_attempt_answers',
            'assessment_attempt_items',
            'assessment_attempt_active_guards',
            'assessment_attempts',
        ] as $table) {
            if ($schema->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        self::assertAttemptTablesEmpty($connection);
    }

    public static function assertAttemptTablesEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach ([
            'assessment_attempt_answers',
            'assessment_attempt_items',
            'assessment_attempt_active_guards',
            'assessment_attempts',
        ] as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            Assert::assertSame(0, $count, \sprintf('Expected %s empty after attempt cleanup.', $table));
        }
    }
}
