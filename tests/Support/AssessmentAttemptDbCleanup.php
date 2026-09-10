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
 *
 * in_progress rows must become terminal before guard/attempt DELETE so
 * trg_assessment_attempt_active_guards_bd and AU guard sync succeed.
 */
final class AssessmentAttemptDbCleanup
{
    public static function deleteAttempts(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['assessment_attempts'])) {
            return;
        }

        if ($schema->tablesExist(['assessment_attempt_answers'])) {
            $connection->executeStatement('DELETE FROM assessment_attempt_answers');
        }
        if ($schema->tablesExist(['assessment_attempt_items'])) {
            $connection->executeStatement('DELETE FROM assessment_attempt_items');
        }

        // Mark in_progress terminal so AU deletes guards and BD allows remaining deletes.
        $connection->executeStatement(<<<'SQL'
            UPDATE assessment_attempts
               SET status = 'expired',
                   expired_at = UTC_TIMESTAMP(),
                   last_activity_at = IF(last_activity_at > UTC_TIMESTAMP(), last_activity_at, UTC_TIMESTAMP()),
                   updated_at = UTC_TIMESTAMP()
             WHERE status = 'in_progress'
            SQL);

        if ($schema->tablesExist(['assessment_attempt_active_guards'])) {
            $connection->executeStatement('DELETE FROM assessment_attempt_active_guards');
        }
        $connection->executeStatement('DELETE FROM assessment_attempts');

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
