<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\AccountType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\RegistrationFlow;
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

    public function testRegistrationFlowMapsSafeRolesOnly(): void
    {
        self::assertSame(UserRole::Student, RegistrationFlow::Student->initialGlobalRole());
        self::assertSame(UserRole::Parent, RegistrationFlow::Parent->initialGlobalRole());
        self::assertSame(UserRole::User, RegistrationFlow::TeacherApplication->initialGlobalRole());
        self::assertSame(UserRole::User, RegistrationFlow::InstitutionApplication->initialGlobalRole());
        self::assertNull(RegistrationFlow::TeacherApplication->accountType());
        self::assertTrue(RegistrationFlow::TeacherApplication->requiresApplicationFollowUp());
        self::assertFalse(RegistrationFlow::Student->requiresApplicationFollowUp());
        self::assertCount(4, RegistrationFlow::publicChoices());
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
