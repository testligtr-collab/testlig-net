<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UsersMigrationTest extends KernelTestCase
{
    public function testUsersTableExistsWithExpectedConstraintsOnMariaDb(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $platform = $connection->getDatabasePlatform();

        if (!$platform instanceof MariaDBPlatform && !$platform instanceof MySQLPlatform) {
            self::markTestSkipped('Users migration assertions target MariaDB/MySQL.');
        }

        $schema = $connection->createSchemaManager();
        self::assertTrue($schema->tablesExist(['users']));

        $table = $schema->introspectTable('users');
        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->hasColumn('normalized_email'));
        self::assertTrue($table->hasColumn('global_roles'));
        self::assertTrue($table->hasColumn('status'));

        $primary = $table->getPrimaryKey();
        self::assertNotNull($primary);
        self::assertSame(['id'], $primary->getColumns());

        $uniqueNames = [];
        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && !$index->isPrimary()) {
                $uniqueNames[] = $index->getName();
            }
        }
        self::assertContains('uniq_users_normalized_email', $uniqueNames);
    }
}
