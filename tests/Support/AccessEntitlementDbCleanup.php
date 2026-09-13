<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for Stage 2.16 access entitlement tables.
 *
 * Active version/seat guards block CASCADE deletes while parents stay active —
 * supersede/revoke first so AU triggers detach guards.
 */
final class AccessEntitlementDbCleanup
{
    public static function deleteAll(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();

        if ($schema->tablesExist(['institution_license_seats'])) {
            $connection->executeStatement(
                "UPDATE institution_license_seats
                 SET status = 'revoked',
                     revoked_at = UTC_TIMESTAMP(),
                     revoked_by_id = assigned_by_id,
                     revocation_reason_code = 'test_cleanup'
                 WHERE status = 'active'",
            );
        }

        if ($schema->tablesExist(['access_package_versions'])) {
            // Draft-only grant DELETE triggers (Version20260912170000) require draft status
            // before grant rows can be removed — including CASCADE from version delete.
            $connection->executeStatement(
                "UPDATE access_package_versions
                 SET status = 'draft',
                     activated_at = NULL,
                     activated_by_id = NULL,
                     superseded_at = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE status IN ('active', 'superseded')",
            );
        }

        foreach ([
            'institution_license_active_seat_guards',
            'institution_license_seats',
            'access_licenses',
            'access_package_learning_content_grants',
            'access_package_assessment_grants',
            'access_package_catalog_grants',
            'access_package_active_version_guards',
            'access_package_versions',
            'access_packages',
            'learning_content_access_policies',
            'assessment_access_policies',
        ] as $table) {
            if ($schema->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        self::assertEmpty($connection);
    }

    public static function assertEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach ([
            'institution_license_active_seat_guards',
            'institution_license_seats',
            'access_licenses',
            'access_package_learning_content_grants',
            'access_package_assessment_grants',
            'access_package_catalog_grants',
            'access_package_active_version_guards',
            'access_package_versions',
            'access_packages',
            'learning_content_access_policies',
            'assessment_access_policies',
        ] as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            Assert::assertSame(0, $count, \sprintf('Expected %s empty after access entitlement cleanup.', $table));
        }
    }
}
