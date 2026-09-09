<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for question-bank and related fixtures.
 *
 * Does not bypass MariaDB append-only DELETE triggers. Immutable child rows are
 * removed only via ON DELETE CASCADE from parent `questions` (MariaDB does not
 * fire child DELETE triggers for FK cascading actions).
 */
final class QuestionBankDbCleanup
{
    /**
     * @param list<string> $tablesAfterQuestions FK-safe delete order for non-question tables
     */
    public static function deleteTables(Connection $connection, array $tablesAfterQuestions = []): void
    {
        $schema = $connection->createSchemaManager();

        // Parent cascade clears revisions/options/answer_keys/alignments/guards.
        if ($schema->tablesExist(['questions'])) {
            $connection->executeStatement('DELETE FROM questions');
        }

        foreach ($tablesAfterQuestions as $table) {
            if ('questions' === $table
                || str_starts_with($table, 'question_revision')
                || 'question_answer_keys' === $table
                || 'question_revision_primary_alignment_guards' === $table) {
                continue;
            }
            if ($schema->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }

        self::assertImmutableQuestionTablesEmpty($connection);
    }

    public static function assertImmutableQuestionTablesEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach ([
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
        ] as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            Assert::assertSame(0, $count, \sprintf('Expected %s empty after cascade cleanup.', $table));
        }
    }
}
