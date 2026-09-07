<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Enum\UserRole;
use App\Exception\InvalidUserTransitionException;
use App\Service\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRolesTest extends KernelTestCase
{
    public function testGetRolesAlwaysIncludesRoleUserSorted(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->create('roles-sort@example.com', 'Plain-Password-123!', 'Sort', 'User', UserRole::ExpertTeacher);

        self::assertSame(
            [UserRole::ExpertTeacher->value, UserRole::User->value],
            $user->getRoles()
        );
    }

    public function testUndefinedRoleStringIsRejectedByEnum(): void
    {
        $this->expectException(\ValueError::class);
        UserRole::from('ROLE_DOES_NOT_EXIST');
    }

    public function testEntityRejectsNonEnumRoleValues(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->create('enum-only@example.com', 'Plain-Password-123!', 'Enum', 'User', UserRole::User);

        $this->expectException(InvalidUserTransitionException::class);
        $user->setGlobalRoles(['ROLE_TEACHER']);
    }
}
