<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\AccountType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

final class OnboardingEnumsTest extends TestCase
{
    public function testAccountTypeMapsOnlyStudentAndParentRoles(): void
    {
        self::assertSame(UserRole::Student, AccountType::Student->initialGlobalRole());
        self::assertSame(UserRole::Parent, AccountType::Parent->initialGlobalRole());
        self::assertCount(2, AccountType::cases());
    }

    public function testApplicationStatusTransitionsAndResubmit(): void
    {
        self::assertTrue(OnboardingApplicationStatus::Pending->isOpen());
        self::assertTrue(OnboardingApplicationStatus::Pending->canTransitionTo(OnboardingApplicationStatus::Rejected));
        self::assertFalse(OnboardingApplicationStatus::Approved->canTransitionTo(OnboardingApplicationStatus::Pending));
        self::assertTrue(OnboardingApplicationStatus::Rejected->allowsResubmit());
        self::assertTrue(OnboardingApplicationStatus::Withdrawn->allowsResubmit());
        self::assertFalse(OnboardingApplicationStatus::Pending->allowsResubmit());
        self::assertFalse(OnboardingApplicationStatus::Approved->allowsResubmit());
    }
}
