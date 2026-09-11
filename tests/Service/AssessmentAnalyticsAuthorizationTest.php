<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AnalyticsFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\UserRole;
use App\Exception\AssessmentAnalyticsException;
use App\Tests\Support\AssessmentAnalyticsTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentAnalyticsAuthorizationTest extends KernelTestCase
{
    use AssessmentAnalyticsTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testOwnerAndManagerAllowSummary(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aaa1');
        unset($attempt);
        $institution = $fx['institution'];
        $owner = $this->reloadUser($fx['owner']->getId());
        $manager = $this->activeUser('aaa1-mgr@example.com');
        $this->membershipManager()->addMember(
            $institution,
            $owner,
            $manager,
            InstitutionMembershipRole::Manager,
            'add_mgr',
        );

        $this->analyticsReader()->readAssessmentSummary($owner, $fx['delivery']->getId());
        $managerView = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($manager->getId()),
            $fx['delivery']->getId(),
        );
        self::assertTrue($managerView->getDeliveryId()->equals($fx['delivery']->getId()));
    }

    public function testTeacherOwnClassroomAllowOtherDeny(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aaa2');
        unset($attempt);
        $teacher = $this->reloadUser($fx['teacher']->getId());
        $this->analyticsReader()->readAssessmentSummary($teacher, $fx['delivery']->getId());

        $otherTeacher = $this->activeUser('aaa2-other-t@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $this->reloadUser($fx['owner']->getId()),
            $otherTeacher,
            InstitutionMembershipRole::Teacher,
            'add_other_t',
        );
        try {
            $this->analyticsReader()->readAssessmentSummary(
                $this->reloadUser($otherTeacher->getId()),
                $fx['delivery']->getId(),
            );
            self::fail('Expected other classroom teacher denied');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testGlobalRoleTeacherAloneDenied(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aaa3');
        unset($attempt);
        $lone = $this->activeUser('aaa3-lone@example.com', UserRole::Teacher);
        try {
            $this->analyticsReader()->readAssessmentSummary(
                $this->reloadUser($lone->getId()),
                $fx['delivery']->getId(),
            );
            self::fail('Expected global ROLE_TEACHER denied');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStudentOtherDenied(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aaa4');
        $other = $this->activeUser('aaa4-other@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $this->reloadUser($fx['owner']->getId()),
            $other,
            InstitutionMembershipRole::Student,
            'add_other',
        );
        try {
            $this->analyticsReader()->readStudentAnalytics(
                $this->reloadUser($other->getId()),
                $attempt->getId(),
            );
            self::fail('Expected other student denied');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaffAdminModeratorDenied(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aaa5');
        $owner = $this->reloadUser($fx['owner']->getId());
        $staff = $this->activeUser('aaa5-staff@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $owner,
            $staff,
            InstitutionMembershipRole::Staff,
            'add_staff',
        );
        $admin = $this->activeUser('aaa5-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $mod = $this->activeUser('aaa5-mod@example.com', UserRole::Moderator);

        foreach ([$staff, $admin, $mod] as $actor) {
            try {
                $this->analyticsReader()->readAssessmentSummary(
                    $this->reloadUser($actor->getId()),
                    $fx['delivery']->getId(),
                );
                self::fail('Expected denied for '.$actor->getEmail());
            } catch (AssessmentAnalyticsException $e) {
                self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
            }
            try {
                $this->analyticsReader()->readStudentAnalytics(
                    $this->reloadUser($actor->getId()),
                    $attempt->getId(),
                );
                self::fail('Expected student DTO denied for '.$actor->getEmail());
            } catch (AssessmentAnalyticsException $e) {
                self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
            }
        }
    }

    public function testSuperAdminSummaryAllowStudentDtoDeny(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aaa6');
        $sa = $this->reloadUser($fx['sa']->getId());
        $this->analyticsReader()->readAssessmentSummary($sa, $fx['delivery']->getId());
        try {
            $this->analyticsReader()->readStudentAnalytics($sa, $attempt->getId());
            self::fail('Expected SUPER_ADMIN student analytics deny');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testCrossTenantIdorDenied(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aaa7a');
        unset($attempt);
        [$ownerB, , $institutionB] = $this->readyClassroom('aaa7b');
        unset($institutionB);
        try {
            $this->analyticsReader()->readAssessmentSummary(
                $this->reloadUser($ownerB->getId()),
                $fx['delivery']->getId(),
            );
            self::fail('Expected cross-tenant deny');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
        }
    }
}
