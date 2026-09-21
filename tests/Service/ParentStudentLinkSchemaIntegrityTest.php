<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ParentStudentLink;
use App\Entity\ParentStudentLinkActiveGuard;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * MariaDB shape + live uniqueness for Stage 2.22.5a parent–student link tables.
 *
 * Verified status exists as a column/CHECK value only — no accept route or child-data access.
 */
final class ParentStudentLinkSchemaIntegrityTest extends KernelTestCase
{
    public function testParentStudentLinkTablesHaveActiveGuardAndLifecycleChecks(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();

        $tables = $connection->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('parent_student_links', 'parent_student_link_active_guards')",
        );
        sort($tables);
        self::assertSame(['parent_student_link_active_guards', 'parent_student_links'], $tables);

        $guardPk = $connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'parent_student_link_active_guards'
               AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION",
        );
        self::assertSame(['parent_user_id', 'student_user_id'], $guardPk);

        $linkUnique = $connection->fetchAssociative(
            "SELECT INDEX_NAME, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'parent_student_link_active_guards'
               AND INDEX_NAME = 'uniq_psl_active_guard_link'
             LIMIT 1",
        );
        self::assertIsArray($linkUnique);
        self::assertSame(0, (int) $linkUnique['NON_UNIQUE']);

        $checkNames = $connection->fetchFirstColumn(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('parent_student_links', 'parent_student_link_active_guards')
               AND CONSTRAINT_TYPE = 'CHECK'
             ORDER BY CONSTRAINT_NAME",
        );
        foreach ([
            'chk_psl_status',
            'chk_psl_parent_ne_student',
            'chk_psl_lifecycle',
            'chk_psl_guard_parent_ne_student',
        ] as $expected) {
            self::assertContains($expected, $checkNames);
        }

        $linkFk = $connection->fetchAssociative(
            "SELECT CONSTRAINT_NAME, DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = 'FK_8C77D151ADA40271'",
        );
        self::assertIsArray($linkFk);
        self::assertSame('CASCADE', $linkFk['DELETE_RULE']);

        foreach (['FK_8533BB17D526A7D3', 'FK_8533BB174A58666D', 'FK_8C77D151D526A7D3', 'FK_8C77D1514A58666D'] as $userFk) {
            $rule = $connection->fetchOne(
                'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
                [$userFk],
            );
            self::assertSame('RESTRICT', $rule, $userFk.' must RESTRICT user deletes');
        }
    }

    public function testMariaDbRejectsDuplicateActiveGuardAndAllowsAfterGuardRelease(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(4));
        $now = new \DateTimeImmutable('2026-09-21T16:00:00+00:00');
        $parent = $this->persistUser($em, "psl-parent-{$suffix}@example.com");
        $student = $this->persistUser($em, "psl-student-{$suffix}@example.com");
        $parentId = $parent->getId();
        $studentId = $student->getId();

        $first = ParentStudentLink::createPending($parent, $student, $parent, $now);
        $em->persist($first);
        $em->persist(ParentStudentLinkActiveGuard::bind($first));
        $em->flush();
        $firstId = $first->getId();

        $second = ParentStudentLink::createPending($parent, $student, $student, $now->modify('+1 minute'));
        $em->persist($second);
        $em->flush();
        $secondId = $second->getId();

        // Bypass Doctrine identity map so MariaDB PRIMARY KEY is what rejects the duplicate pair.
        $rejected = false;
        try {
            $em->getConnection()->insert('parent_student_link_active_guards', [
                'parent_user_id' => $parentId->toBinary(),
                'student_user_id' => $studentId->toBinary(),
                'link_id' => $secondId->toBinary(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'second active guard for the same parent/student pair must hit MariaDB PK');

        $first = $em->find(ParentStudentLink::class, $firstId);
        self::assertInstanceOf(ParentStudentLink::class, $first);
        $guard = $em->find(ParentStudentLinkActiveGuard::class, [
            'parent' => $parentId,
            'student' => $studentId,
        ]);
        self::assertInstanceOf(ParentStudentLinkActiveGuard::class, $guard);

        $em->wrapInTransaction(static function () use ($em, $first, $guard, $now): void {
            $em->remove($guard);
            $first->markEnded($now->modify('+1 hour'));
            $em->flush();
        });

        $parent = $em->find(User::class, $parentId);
        $student = $em->find(User::class, $studentId);
        self::assertInstanceOf(User::class, $parent);
        self::assertInstanceOf(User::class, $student);

        $replacement = ParentStudentLink::createPending($parent, $student, $parent, $now->modify('+2 hours'));
        $em->persist($replacement);
        $em->persist(ParentStudentLinkActiveGuard::bind($replacement));
        $em->flush();

        self::assertTrue($replacement->isPending());
        self::assertNotNull($em->find(ParentStudentLinkActiveGuard::class, [
            'parent' => $parentId,
            'student' => $studentId,
        ]));

        $em->wrapInTransaction(static function () use ($em, $parentId, $studentId, $secondId): void {
            $active = $em->find(ParentStudentLinkActiveGuard::class, [
                'parent' => $parentId,
                'student' => $studentId,
            ]);
            if ($active instanceof ParentStudentLinkActiveGuard) {
                $em->remove($active);
            }
            $orphan = $em->find(ParentStudentLink::class, $secondId);
            if ($orphan instanceof ParentStudentLink) {
                $em->remove($orphan);
            }
            $parent = $em->find(User::class, $parentId);
            $student = $em->find(User::class, $studentId);
            foreach ($em->getRepository(ParentStudentLink::class)->findBy([
                'parent' => $parent,
                'student' => $student,
            ]) as $link) {
                $em->remove($link);
            }
            if ($parent instanceof User) {
                $em->remove($parent);
            }
            if ($student instanceof User) {
                $em->remove($student);
            }
            $em->flush();
        });
    }

    private function persistUser(EntityManagerInterface $em, string $email): User
    {
        $user = User::create(
            email: $email,
            normalizedEmail: strtolower($email),
            firstName: 'Psl',
            lastName: 'Fixture',
            passwordHash: '!',
            initialRole: UserRole::User,
            id: new UuidV7(),
        );
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
