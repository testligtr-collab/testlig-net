<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AnalyticsFailureReason;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\UserStatus;
use App\Exception\AssessmentAnalyticsException;
use App\Tests\Support\AssessmentAnalyticsTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentAnalyticsFreshStaleTest extends KernelTestCase
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

    public function testStaleMembershipEndedDeniesSummary(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aafs1');
        unset($attempt);
        $teacher = $this->reloadUser($fx['teacher']->getId());
        self::assertSame(
            InstitutionMembershipStatus::Active,
            $fx['teacherMembership']->getStatus(),
        );

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Ended->value, $fx['teacherMembership']->getId()->toBinary()],
        );
        self::assertSame(
            InstitutionMembershipStatus::Active,
            $fx['teacherMembership']->getStatus(),
            'Precondition: managed membership still Active',
        );

        try {
            $this->analyticsReader()->readAssessmentSummary($teacher, $fx['delivery']->getId());
            self::fail('Expected stale ended membership denied');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleSuspendedUserDeniesSummary(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aafs2');
        unset($attempt);
        $owner = $this->reloadUser($fx['owner']->getId());
        self::assertSame(UserStatus::Active, $owner->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $owner->getId()->toBinary()],
        );
        self::assertSame(UserStatus::Active, $owner->getStatus(), 'Precondition: managed still Active');

        try {
            $this->analyticsReader()->readAssessmentSummary($owner, $fx['delivery']->getId());
            self::fail('Expected suspended actor denied');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(AnalyticsFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testWithdrawnReleaseDeniesStudentAnalyticsWithoutClear(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aafs3');
        $releases = $this->resultReleases()->findBy(['attempt' => $attempt]);
        self::assertCount(1, $releases);
        $release = $releases[0];
        self::assertSame(\App\Enum\ResultReleaseStatus::Released, $release->getStatus());

        // Withdraw via DBAL; do not clear/detach — managed entity still looks Released.
        $this->em->getConnection()->executeStatement(
            'UPDATE assessment_result_releases
             SET status = ?, withdrawn_at = UTC_TIMESTAMP(), withdrawn_by_id = ?
             WHERE id = ?',
            [
                \App\Enum\ResultReleaseStatus::Withdrawn->value,
                $fx['owner']->getId()->toBinary(),
                $release->getId()->toBinary(),
            ],
        );
        self::assertSame(
            \App\Enum\ResultReleaseStatus::Released,
            $release->getStatus(),
            'Precondition: managed release still Released',
        );

        try {
            $this->analyticsReader()->readStudentAnalytics(
                $this->reloadUser($fx['owner']->getId()),
                $attempt->getId(),
            );
            self::fail('Expected withdrawn release denied');
        } catch (AssessmentAnalyticsException $e) {
            self::assertTrue(\in_array($e->getReason(), [
                AnalyticsFailureReason::ResultNotReleased,
                AnalyticsFailureReason::ResultWithdrawn,
            ], true));
        }
    }
}
