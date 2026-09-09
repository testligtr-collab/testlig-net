<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Test-only cleanup for append-only question bank tables.
 *
 * Production application code never sets @testlig_immutable_delete_bypass.
 */
final class QuestionBankDbCleanup
{
    /**
     * @param list<string> $tables
     */
    public static function deleteTables(Connection $connection, array $tables): void
    {
        $connection->executeStatement('SET @testlig_immutable_delete_bypass = 1');
        try {
            foreach ($tables as $table) {
                if ($connection->createSchemaManager()->tablesExist([$table])) {
                    $connection->executeStatement('DELETE FROM '.$table);
                }
            }
        } finally {
            $connection->executeStatement('SET @testlig_immutable_delete_bypass = NULL');
        }
    }
}
