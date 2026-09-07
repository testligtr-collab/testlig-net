<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class RoleHierarchyTest extends KernelTestCase
{
    public function testSuperAdminInheritsAdminModeratorAndUser(): void
    {
        self::bootKernel();
        /** @var RoleHierarchyInterface $hierarchy */
        $hierarchy = static::getContainer()->get(RoleHierarchyInterface::class);

        $reachable = $hierarchy->getReachableRoleNames(['ROLE_SUPER_ADMIN']);

        self::assertContains('ROLE_SUPER_ADMIN', $reachable);
        self::assertContains('ROLE_ADMIN', $reachable);
        self::assertContains('ROLE_MODERATOR', $reachable);
        self::assertContains('ROLE_USER', $reachable);
    }

    public function testExpertAndHeadTeacherInheritTeacherButNotEachOther(): void
    {
        self::bootKernel();
        /** @var RoleHierarchyInterface $hierarchy */
        $hierarchy = static::getContainer()->get(RoleHierarchyInterface::class);

        $expert = $hierarchy->getReachableRoleNames(['ROLE_EXPERT_TEACHER']);
        $head = $hierarchy->getReachableRoleNames(['ROLE_HEAD_TEACHER']);

        self::assertContains('ROLE_TEACHER', $expert);
        self::assertContains('ROLE_TEACHER', $head);
        self::assertNotContains('ROLE_HEAD_TEACHER', $expert);
        self::assertNotContains('ROLE_EXPERT_TEACHER', $head);
    }

    public function testStudentParentAndInstitutionManagerDoNotInheritEachOther(): void
    {
        self::bootKernel();
        /** @var RoleHierarchyInterface $hierarchy */
        $hierarchy = static::getContainer()->get(RoleHierarchyInterface::class);

        $student = $hierarchy->getReachableRoleNames(['ROLE_STUDENT']);
        $parent = $hierarchy->getReachableRoleNames(['ROLE_PARENT']);
        $manager = $hierarchy->getReachableRoleNames(['ROLE_INSTITUTION_MANAGER']);

        self::assertSame(['ROLE_STUDENT'], $student);
        self::assertSame(['ROLE_PARENT'], $parent);
        self::assertSame(['ROLE_INSTITUTION_MANAGER'], $manager);
    }
}
