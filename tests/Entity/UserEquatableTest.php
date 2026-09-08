<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserEquatableTest extends KernelTestCase
{
    public function testSameRolesDifferentOrderRemainEqual(): void
    {
        $user = $this->newActiveUser();
        $user->setGlobalRoles([UserRole::Admin, UserRole::Student]);
        $other = clone $user;
        $other->setGlobalRoles([UserRole::Student, UserRole::Admin]);

        self::assertTrue($user->isEqualTo($other));
        self::assertSame($user->getRoles(), $other->getRoles());
    }

    public function testRemovingRoleBreaksEquality(): void
    {
        $user = $this->newActiveUser();
        $user->setGlobalRoles([UserRole::Admin, UserRole::Student]);
        $other = clone $user;
        $user->setGlobalRoles([UserRole::Student]);

        self::assertFalse($user->isEqualTo($other));
    }

    public function testAddingRoleBreaksEquality(): void
    {
        $user = $this->newActiveUser();
        $user->setGlobalRoles([UserRole::Student]);
        $other = clone $user;
        $user->addGlobalRole(UserRole::Admin);

        self::assertFalse($user->isEqualTo($other));
    }

    public function testStatusChangeBreaksEquality(): void
    {
        $user = $this->newActiveUser();
        $other = clone $user;
        $user->transitionTo(UserStatus::Suspended);

        self::assertFalse($user->isEqualTo($other));
    }

    public function testPasswordChangeBreaksEquality(): void
    {
        $user = $this->newActiveUser();
        $other = clone $user;
        $user->setPassword('different-hash-value');

        self::assertFalse($user->isEqualTo($other));
    }

    private function newActiveUser(): User
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->create('eq-roles@example.com', 'Guclu-Parola-123!', 'Eq', 'Roles', UserRole::Student);
        $user->transitionTo(UserStatus::Active);

        return $user;
    }
}
