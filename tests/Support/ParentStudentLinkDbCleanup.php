<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Test-only cleanup for parent–student links and personal invitations.
 *
 * FK-safe order before deleting users (RESTRICT on link/invitation user columns):
 * active guards → links → open link codes → personal invitations → participation codes.
 */
final class ParentStudentLinkDbCleanup
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'parent_student_link_active_guards',
        'parent_student_links',
        'parent_student_link_codes',
        'personal_invitations',
        'participation_codes',
    ];

    public static function deleteAll(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach (self::TABLES as $table) {
            if ($schema->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }
}
