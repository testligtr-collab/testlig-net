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
 *
 * Scoring/result history: many rows are append-only (DELETE triggers). Those are
 * cleared via ON DELETE CASCADE from assessment_attempts (MariaDB does not fire
 * child DELETE triggers for FK cascading actions). Open (non-terminal) scoring
 * rows are deleted first in Stage 2.12 dependency order.
 */
final class AssessmentAttemptDbCleanup
{
    public static function deleteAttempts(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['assessment_attempts'])) {
            return;
        }

        self::deleteScoringResultRows($connection);

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

    /**
     * Clears scoring/result tables before attempt deletes.
     *
     * Order: manual_grade_decisions → item_scores → result_active_release_guards
     * → result_releases → scoring_runs.
     *
     * Append-only / immutability DELETE triggers block terminal rows; those are
     * removed by the subsequent assessment_attempts DELETE (CASCADE).
     */
    public static function deleteScoringResultRows(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['assessment_scoring_runs'])) {
            return;
        }

        $hasAppendOnly = (int) $connection->fetchOne(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM assessment_manual_grade_decisions)
              + (SELECT COUNT(*) FROM assessment_result_releases)
              + (SELECT COUNT(*) FROM assessment_scoring_runs WHERE status IN ('completed', 'failed'))
            SQL);

        if ($hasAppendOnly > 0) {
            // Terminal / append-only rows rely on attempt CASCADE (triggers not fired).
            return;
        }

        if ($schema->tablesExist(['assessment_manual_grade_decisions'])) {
            $connection->executeStatement('DELETE FROM assessment_manual_grade_decisions');
        }
        if ($schema->tablesExist(['assessment_item_scores'])) {
            $connection->executeStatement('DELETE FROM assessment_item_scores');
        }
        if ($schema->tablesExist(['assessment_result_active_release_guards'])) {
            $connection->executeStatement('DELETE FROM assessment_result_active_release_guards');
        }
        if ($schema->tablesExist(['assessment_result_releases'])) {
            $connection->executeStatement('DELETE FROM assessment_result_releases');
        }
        $connection->executeStatement('DELETE FROM assessment_scoring_runs');
    }

    public static function assertAttemptTablesEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach ([
            'assessment_manual_grade_decisions',
            'assessment_item_scores',
            'assessment_result_active_release_guards',
            'assessment_result_releases',
            'assessment_scoring_runs',
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
