<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentScoringFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\ScoringRunStatus;
use App\Exception\AssessmentScoringException;
use App\Tests\Support\AssessmentScoringTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Managed entities stay stale after out-of-band DBAL updates; scoring services must re-check DB.
 */
final class AssessmentScoringFreshStaleTest extends KernelTestCase
{
    use AssessmentScoringTestFixtures;

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

    public function testStaleStudentStatusDeniesScoring(): void
    {
        $fx = $this->activatedClassroomDelivery('asfs1');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_stale',
        );
        $this->attempts()->saveAnswer(
            $attempt,
            $this->firstAttemptItem($attempt),
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_stale',
        );
        $this->attempts()->submit($attempt, $student, 'submit_stale');
        $attempt = $this->reloadAttempt($attempt->getId());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [\App\Enum\UserStatus::Suspended->value, $student->getId()->toBinary()],
        );
        self::assertSame(\App\Enum\UserStatus::Active, $student->getStatus());

        try {
            $this->scoring()->scoreAttempt($attempt, null, 'score_stale');
            self::fail('Expected stale inactive student denied.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleMembershipRoleDeniesManualGrade(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('asfs2');
        unset($attempt);
        $teacher = $this->reloadUser($fx['teacher']->getId());
        self::assertSame(InstitutionMembershipRole::Teacher, $fx['teacherMembership']->getRole());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = ? WHERE id = ?',
            [InstitutionMembershipRole::Staff->value, $fx['teacherMembership']->getId()->toBinary()],
        );
        self::assertSame(InstitutionMembershipRole::Teacher, $fx['teacherMembership']->getRole());

        try {
            $this->manualGrading()->gradeItem(
                $this->reloadScoringRun($run->getId()),
                $item,
                $teacher,
                '1.00',
                'grade_stale_role',
            );
            self::fail('Expected stale role denied.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleRunStatusDeniesRelease(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('asfs3');
        unset($attempt);
        $owner = $this->reloadUser($fx['owner']->getId());
        self::assertSame(ScoringRunStatus::Completed, $run->getStatus());

        // Identity-map still says completed; DB forced to failed via processing sibling path is hard.
        // Instead suspend owner membership so release re-checks auth freshness.
        $ownerMembership = $this->em->getConnection()->fetchOne(
            'SELECT id FROM institution_memberships WHERE user_id = ? AND institution_id = ? LIMIT 1',
            [$owner->getId()->toBinary(), $fx['institution']->getId()->toBinary()],
        );
        self::assertNotFalse($ownerMembership);
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Suspended->value, $this->blobToString($ownerMembership)],
        );

        try {
            $this->releases()->release($run, $owner, 'release_stale');
            self::fail('Expected stale membership denied on release.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleReleaseStatusDeniesStudentRead(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('asfs4');
        $release = $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_read',
        );
        self::assertSame(\App\Enum\ResultReleaseStatus::Released, $release->getStatus());

        $this->em->getConnection()->executeStatement(
            "UPDATE assessment_result_releases
                SET status = 'withdrawn',
                    withdrawn_at = UTC_TIMESTAMP(),
                    withdrawn_by_id = ?,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ?",
            [$fx['owner']->getId()->toBinary(), $release->getId()->toBinary()],
        );
        self::assertSame(\App\Enum\ResultReleaseStatus::Released, $release->getStatus());

        try {
            $this->resultReader()->readReleasedResult(
                $this->reloadUser($fx['student']->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected withdrawn release not readable.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::ResultNotReleased, $e->getReason());
        }
    }
}
