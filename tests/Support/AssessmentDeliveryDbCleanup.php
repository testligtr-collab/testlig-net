<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for assessment delivery fixtures.
 *
 * Deletes parent deliveries; recipients cascade. Does not use FOREIGN_KEY_CHECKS
 * or session-variable bypasses.
 */
final class AssessmentDeliveryDbCleanup
{
    public static function deleteDeliveries(Connection $connection): void
    {
        // Attempts RESTRICT on delivery — wipe attempts first.
        AssessmentAttemptDbCleanup::deleteAttempts($connection);

        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['assessment_deliveries'])) {
            $connection->executeStatement('DELETE FROM assessment_deliveries');
        }
        self::assertDeliveryTablesEmpty($connection);
    }

    public static function assertDeliveryTablesEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach (['assessment_delivery_recipients', 'assessment_deliveries'] as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            Assert::assertSame(0, $count, \sprintf('Expected %s empty after cascade cleanup.', $table));
        }
    }
}
