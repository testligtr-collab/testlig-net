<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentScoringFailureReason;
use App\Enum\ResultReleaseStatus;
use App\Enum\ScoringRunStatus;
use App\Exception\AssessmentScoringException;
use App\Tests\Support\AssessmentScoringTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

final class AssessmentResultReleaseManagerTest extends KernelTestCase
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

    public function testReleaseCompletedOnly(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arr1');
        $release = $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_ok',
        );
        self::assertSame(ResultReleaseStatus::Released, $release->getStatus());
        self::assertSame(1, $release->getReleaseNumber());
        self::assertTrue($release->getAttempt()->getId()->equals($attempt->getId()));
    }

    public function testRejectPendingManualAndFailed(): void
    {
        [, $pending, $fx] = $this->submitAndScorePendingManual('arr2');
        try {
            $this->releases()->release(
                $this->reloadScoringRun($pending->getId()),
                $this->reloadUser($fx['owner']->getId()),
                'release_pm',
            );
            self::fail('Expected pending_manual release rejected.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::ReleaseNotAllowed, $e->getReason());
        }

        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        [$attempt, $completedRun, $fx] = $this->submitAndScoreClassroomAttempt('arr2b');
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $failedId = Uuid::v7();
        $nextRun = $completedRun->getRunNumber() + 1;
        $this->em->getConnection()->insert('assessment_scoring_runs', [
            'id' => $failedId->toBinary(),
            'attempt_id' => $attempt->getId()->toBinary(),
            'institution_id' => $fx['institution']->getId()->toBinary(),
            'delivery_id' => $fx['delivery']->getId()->toBinary(),
            'recipient_id' => $fx['recipient']->getId()->toBinary(),
            'user_id' => $fx['student']->getId()->toBinary(),
            'assessment_id' => $fx['assessment']->getId()->toBinary(),
            'assessment_publication_id' => $fx['publication']->getId()->toBinary(),
            'assessment_revision_id' => $fx['publication']->getAssessmentRevision()->getId()->toBinary(),
            'publication_number' => $fx['publication']->getPublicationNumber(),
            'scoring_policy_id' => 'testlig_default_v1',
            'scoring_version' => 1,
            'run_number' => $nextRun,
            'status' => ScoringRunStatus::Processing->value,
            'raw_points' => '0.00',
            'final_points' => '0.00',
            'maximum_points' => '0.00',
            'percentage' => '0.0000',
            'correct_count' => 0,
            'incorrect_count' => 0,
            'unanswered_count' => 0,
            'manual_pending_count' => 0,
            'started_at' => $now,
            'completed_at' => null,
            'created_by_id' => null,
            'reason_code' => 'failed_fixture',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->em->getConnection()->executeStatement(
            "UPDATE assessment_scoring_runs SET status = 'failed', updated_at = ? WHERE id = ?",
            [$now, $failedId->toBinary()],
        );
        $failed = $this->reloadScoringRun($failedId);
        try {
            $this->releases()->release(
                $failed,
                $this->reloadUser($fx['owner']->getId()),
                'release_fail',
            );
            self::fail('Expected failed release rejected.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::ReleaseNotAllowed, $e->getReason());
        }
    }

    public function testSupersedeWithdrawAndSingleActive(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arr3');
        $owner = $this->reloadUser($fx['owner']->getId());
        $first = $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $owner,
            'release_1',
        );
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_result_active_release_guards WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        ));

        $regrade = $this->scoring()->regradeAttempt(
            $this->reloadAttempt($attempt->getId()),
            null,
            'regrade_arr3',
        );
        $second = $this->releases()->release(
            $this->reloadScoringRun($regrade->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_2',
        );
        self::assertSame(2, $second->getReleaseNumber());
        $first = $this->reloadRelease($first->getId());
        self::assertSame(ResultReleaseStatus::Superseded, $first->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_result_active_release_guards WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        ));
        $activeId = $this->em->getConnection()->fetchOne(
            'SELECT release_id FROM assessment_result_active_release_guards WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertSame($second->getId()->toBinary(), $this->blobToString($activeId));

        $this->releases()->withdraw(
            $this->reloadRelease($second->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'withdraw_2',
        );
        $second = $this->reloadRelease($second->getId());
        self::assertSame(ResultReleaseStatus::Withdrawn, $second->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_result_active_release_guards WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        ));
    }

    public function testCrossAttemptSpoofRejected(): void
    {
        [$attemptA, $runA, $fxA] = $this->submitAndScoreClassroomAttempt('arr4a');
        [$attemptB] = $this->submitAndScoreClassroomAttempt('arr4b');
        unset($attemptA);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        try {
            $this->em->getConnection()->insert('assessment_result_releases', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attemptB->getId()->toBinary(),
                'scoring_run_id' => $runA->getId()->toBinary(),
                'release_number' => 1,
                'status' => ResultReleaseStatus::Released->value,
                'released_by_id' => $fxA['owner']->getId()->toBinary(),
                'released_at' => $now,
                'withdrawn_by_id' => null,
                'withdrawn_at' => null,
                'reason_code' => 'spoof_cross',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected cross-attempt spoof denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }
    }
}
