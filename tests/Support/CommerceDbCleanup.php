<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Test-only cleanup for Stage 2.17 commerce tables.
 *
 * Direct DELETEs on payment_events, granted fulfillments and succeeded refunds are
 * denied by triggers, so a purge always goes through the order root: MariaDB does not
 * fire triggers for foreign-key CASCADE, and every order-rooted child cascades. Run
 * this before AccessEntitlementDbCleanup, because fulfillments hold the RESTRICT
 * reference to access_licenses.
 */
final class CommerceDbCleanup
{
    /**
     * @var list<string>
     */
    private const ROOT_TABLES = [
        'commerce_orders',
        'commercial_offers',
    ];

    /**
     * @var list<string>
     */
    private const TABLES = [
        'payment_webhook_inbox_events',
        'commerce_fulfillments',
        'payment_refunds',
        'payment_events',
        'payment_attempts',
        'commerce_subscriptions',
        'commerce_order_items',
        'commerce_orders',
        'commercial_offers',
    ];

    public static function deleteAll(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['payment_webhook_inbox_events'])) {
            // Append-only DELETE trigger; TRUNCATE is test-only purge.
            $connection->executeStatement('TRUNCATE TABLE payment_webhook_inbox_events');
        }
        foreach (self::ROOT_TABLES as $table) {
            if ($schema->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        self::assertEmpty($connection);
    }

    public static function assertEmpty(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach (self::TABLES as $table) {
            if (!$schema->tablesExist([$table])) {
                continue;
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            Assert::assertSame(0, $count, \sprintf('Expected %s empty after commerce cleanup.', $table));
        }
    }
}
