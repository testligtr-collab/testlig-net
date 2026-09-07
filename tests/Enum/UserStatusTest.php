<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\UserStatus;
use PHPUnit\Framework\TestCase;

final class UserStatusTest extends TestCase
{
    public function testOnlyActiveCanAuthenticate(): void
    {
        self::assertFalse(UserStatus::PendingVerification->canAuthenticate());
        self::assertTrue(UserStatus::Active->canAuthenticate());
        self::assertFalse(UserStatus::Suspended->canAuthenticate());
        self::assertFalse(UserStatus::Archived->canAuthenticate());
    }

    public function testAllowedTransitions(): void
    {
        self::assertTrue(UserStatus::PendingVerification->canTransitionTo(UserStatus::Active));
        self::assertTrue(UserStatus::PendingVerification->canTransitionTo(UserStatus::Archived));
        self::assertFalse(UserStatus::PendingVerification->canTransitionTo(UserStatus::Suspended));

        self::assertTrue(UserStatus::Active->canTransitionTo(UserStatus::Suspended));
        self::assertTrue(UserStatus::Suspended->canTransitionTo(UserStatus::Active));
        self::assertFalse(UserStatus::Archived->canTransitionTo(UserStatus::Active));
    }
}
