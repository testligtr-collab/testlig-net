<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ItemScoreOutcome;
use App\Enum\ResultReleaseStatus;
use App\Enum\ScoringMethod;
use App\Enum\ScoringRunStatus;
use App\Tests\Support\AssessmentScoringTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Stage 2.12 hardened MariaDB trigger contract (Version20260911120000).
 */
final class AssessmentScoringHardeningDbalTest extends KernelTestCase
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

    public function testInitialInsertNonProcessingRejected(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('ashd1');
        foreach ([
            ScoringRunStatus::Completed,
            ScoringRunStatus::PendingManual,
            ScoringRunStatus::Failed,
        ] as $status) {
            try {
                $this->insertProcessingRunShape($attempt, $fx, 2, [
                    'status' => $status->value,
                ]);
                self::fail('Expected '.$status->value.' insert rejected.');
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertSame('45000', $this->sqlState($e));
            }
        }
    }

    public function testProcessingInsertNonZeroAggregatesRejected(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('ashd2');
        try {
            $this->insertProcessingRunShape($attempt, $fx, 2, [
                'raw_points' => '1.00',
            ]);
            self::fail('Expected non-zero aggregate insert rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testCompletedTransitionWithoutItemScoresRejected(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('ashd3');
        $procId = $this->insertProcessingRunShape($attempt, $fx, 2);
        $now = $this->utcNow();
        try {
            $this->em->getConnection()->executeStatement(
                "UPDATE assessment_scoring_runs
                    SET status = 'completed',
                        raw_points = '2.50',
                        final_points = '2.50',
                        maximum_points = '2.50',
                        percentage = '100.0000',
                        correct_count = 1,
                        incorrect_count = 0,
                        unanswered_count = 0,
                        manual_pending_count = 0,
                        completed_at = ?,
                        updated_at = ?
                  WHERE id = ?",
                [$now, $now, $procId->toBinary()],
            );
            self::fail('Expected completed without scores rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testIncompleteItemCoverageRejected(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('ashd4');
        $procId = $this->insertProcessingRunShape($attempt, $fx, 2);
        // No item_score row inserted — coverage incomplete.
        $now = $this->utcNow();
        try {
            $this->em->getConnection()->executeStatement(
                "UPDATE assessment_scoring_runs
                    SET status = 'pending_manual',
                        raw_points = '0.00',
                        final_points = '0.00',
                        maximum_points = '2.50',
                        percentage = '0.0000',
                        correct_count = 0,
                        incorrect_count = 0,
                        unanswered_count = 0,
                        manual_pending_count = 1,
                        updated_at = ?
                  WHERE id = ?",
                [$now, $procId->toBinary()],
            );
            self::fail('Expected incomplete coverage rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testFakeAggregatesOnCompleteRejected(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('ashd5');
        $procId = $this->insertProcessingRunShape($attempt, $fx, 2);
        $item = $this->firstAttemptItem($attempt);
        $this->insertAutomaticCorrectItemScore($procId, $attempt, $item, $fx);
        $now = $this->utcNow();

        $fakes = [
            ['raw_points' => '9.99', 'final_points' => '9.99', 'maximum_points' => '2.50', 'percentage' => '100.0000', 'correct_count' => 1, 'incorrect_count' => 0, 'unanswered_count' => 0, 'manual_pending_count' => 0],
            ['raw_points' => '2.50', 'final_points' => '2.50', 'maximum_points' => '9.99', 'percentage' => '100.0000', 'correct_count' => 1, 'incorrect_count' => 0, 'unanswered_count' => 0, 'manual_pending_count' => 0],
            ['raw_points' => '2.50', 'final_points' => '1.00', 'maximum_points' => '2.50', 'percentage' => '100.0000', 'correct_count' => 1, 'incorrect_count' => 0, 'unanswered_count' => 0, 'manual_pending_count' => 0],
            ['raw_points' => '2.50', 'final_points' => '2.50', 'maximum_points' => '2.50', 'percentage' => '50.0000', 'correct_count' => 1, 'incorrect_count' => 0, 'unanswered_count' => 0, 'manual_pending_count' => 0],
            ['raw_points' => '2.50', 'final_points' => '2.50', 'maximum_points' => '2.50', 'percentage' => '100.0000', 'correct_count' => 0, 'incorrect_count' => 0, 'unanswered_count' => 0, 'manual_pending_count' => 0],
        ];
        foreach ($fakes as $i => $fake) {
            try {
                $this->em->getConnection()->executeStatement(
                    "UPDATE assessment_scoring_runs
                        SET status = 'completed',
                            raw_points = ?,
                            final_points = ?,
                            maximum_points = ?,
                            percentage = ?,
                            correct_count = ?,
                            incorrect_count = ?,
                            unanswered_count = ?,
                            manual_pending_count = ?,
                            completed_at = ?,
                            updated_at = ?
                      WHERE id = ?",
                    [
                        $fake['raw_points'],
                        $fake['final_points'],
                        $fake['maximum_points'],
                        $fake['percentage'],
                        $fake['correct_count'],
                        $fake['incorrect_count'],
                        $fake['unanswered_count'],
                        $fake['manual_pending_count'],
                        $now,
                        $now,
                        $procId->toBinary(),
                    ],
                );
                self::fail('Expected fake aggregate #'.$i.' rejected.');
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertSame('45000', $this->sqlState($e));
            }
        }
    }

    public function testCompletedWhileManualPendingExistsRejected(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('ashd6');
        unset($attempt, $item);
        $now = $this->utcNow();
        try {
            $this->em->getConnection()->executeStatement(
                "UPDATE assessment_scoring_runs
                    SET status = 'completed',
                        raw_points = '0.00',
                        final_points = '0.00',
                        maximum_points = '2.50',
                        percentage = '0.0000',
                        correct_count = 0,
                        incorrect_count = 0,
                        unanswered_count = 0,
                        manual_pending_count = 0,
                        completed_at = ?,
                        updated_at = ?
                  WHERE id = ?",
                [$now, $now, $run->getId()->toBinary()],
            );
            self::fail('Expected completed with pending manual rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
        unset($fx);
    }

    public function testValidCompleteAccepted(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('ashd7');
        $procId = $this->insertProcessingRunShape($attempt, $fx, 2);
        $item = $this->firstAttemptItem($attempt);
        $this->insertAutomaticCorrectItemScore($procId, $attempt, $item, $fx);
        $now = $this->utcNow();
        $this->em->getConnection()->executeStatement(
            "UPDATE assessment_scoring_runs
                SET status = 'completed',
                    raw_points = '2.50',
                    final_points = '2.50',
                    maximum_points = '2.50',
                    percentage = '100.0000',
                    correct_count = 1,
                    incorrect_count = 0,
                    unanswered_count = 0,
                    manual_pending_count = 0,
                    completed_at = ?,
                    updated_at = ?
              WHERE id = ?",
            [$now, $now, $procId->toBinary()],
        );
        $status = (string) $this->em->getConnection()->fetchOne(
            'SELECT status FROM assessment_scoring_runs WHERE id = ?',
            [$procId->toBinary()],
        );
        self::assertSame(ScoringRunStatus::Completed->value, $status);
    }

    public function testFakeCompletedReleaseRejected(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('ashd8');
        $procId = $this->insertProcessingRunShape($attempt, $fx, 2);
        $now = $this->utcNow();
        try {
            $this->em->getConnection()->insert('assessment_result_releases', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'scoring_run_id' => $procId->toBinary(),
                'release_number' => 1,
                'status' => ResultReleaseStatus::Released->value,
                'released_by_id' => $fx['owner']->getId()->toBinary(),
                'released_at' => $now,
                'withdrawn_by_id' => null,
                'withdrawn_at' => null,
                'reason_code' => 'fake_rel',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected release on non-completed run rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testDecisionlessManuallyGradedUpdateRejected(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('ashd9');
        unset($attempt);
        $score = $this->itemScores()->findForRunAndItem($run->getId(), $item->getId());
        self::assertNotNull($score);
        $now = $this->utcNow();
        try {
            $this->em->getConnection()->executeStatement(
                "UPDATE assessment_item_scores
                    SET outcome = 'manually_graded',
                        awarded_points = '2.00',
                        manual_pending = 0,
                        scoring_method = 'manual',
                        evaluator_user_id = ?,
                        evaluated_at = ?,
                        reason_code = 'no_decision',
                        updated_at = ?
                  WHERE id = ?",
                [
                    $fx['owner']->getId()->toBinary(),
                    $now,
                    $now,
                    $score->getId()->toBinary(),
                ],
            );
            self::fail('Expected decision-less manually_graded rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testDecisionScoreFieldMismatchRejected(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('ashd10');
        $owner = $this->reloadUser($fx['owner']->getId());
        $score = $this->itemScores()->findForRunAndItem($run->getId(), $item->getId());
        self::assertNotNull($score);
        $now = $this->utcNow();
        $this->em->getConnection()->insert('assessment_manual_grade_decisions', [
            'id' => Uuid::v7()->toBinary(),
            'scoring_run_id' => $run->getId()->toBinary(),
            'attempt_id' => $attempt->getId()->toBinary(),
            'attempt_item_id' => $item->getId()->toBinary(),
            'decision_number' => 1,
            'outcome' => ItemScoreOutcome::ManuallyGraded->value,
            'awarded_points' => '2.00',
            'maximum_points' => '2.50',
            'evaluator_user_id' => $owner->getId()->toBinary(),
            'evaluated_at' => $now,
            'reason_code' => 'dec_ok',
            'created_at' => $now,
        ]);
        try {
            $this->em->getConnection()->executeStatement(
                "UPDATE assessment_item_scores
                    SET outcome = 'manually_graded',
                        awarded_points = '1.00',
                        manual_pending = 0,
                        scoring_method = 'manual',
                        evaluator_user_id = ?,
                        evaluated_at = ?,
                        reason_code = 'dec_ok',
                        updated_at = ?
                  WHERE id = ?",
                [$owner->getId()->toBinary(), $now, $now, $score->getId()->toBinary()],
            );
            self::fail('Expected decision/score mismatch rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', 'HY000']);
        }
    }

    public function testDecisionOnNonPendingManualOrCompletedRunRejected(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('ashd11');
        $item = $this->firstAttemptItem($attempt);
        $now = $this->utcNow();
        try {
            $this->em->getConnection()->insert('assessment_manual_grade_decisions', [
                'id' => Uuid::v7()->toBinary(),
                'scoring_run_id' => $run->getId()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'attempt_item_id' => $item->getId()->toBinary(),
                'decision_number' => 1,
                'outcome' => ItemScoreOutcome::ManuallyGraded->value,
                'awarded_points' => '1.00',
                'maximum_points' => '2.50',
                'evaluator_user_id' => $fx['owner']->getId()->toBinary(),
                'evaluated_at' => $now,
                'reason_code' => 'on_completed',
                'created_at' => $now,
            ]);
            self::fail('Expected decision on completed run rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        $procId = $this->insertProcessingRunShape($attempt, $fx, 2);
        $this->insertAutomaticCorrectItemScore($procId, $attempt, $item, $fx);
        try {
            $this->em->getConnection()->insert('assessment_manual_grade_decisions', [
                'id' => Uuid::v7()->toBinary(),
                'scoring_run_id' => $procId->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'attempt_item_id' => $item->getId()->toBinary(),
                'decision_number' => 1,
                'outcome' => ItemScoreOutcome::ManuallyGraded->value,
                'awarded_points' => '1.00',
                'maximum_points' => '2.50',
                'evaluator_user_id' => $fx['owner']->getId()->toBinary(),
                'evaluated_at' => $now,
                'reason_code' => 'on_processing',
                'created_at' => $now,
            ]);
            self::fail('Expected decision on processing run rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testSequentialRunNumberEnforced(): void
    {
        $fx = $this->activatedClassroomDelivery('ashd12');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_seq_run',
        );
        $this->attempts()->saveAnswer(
            $attempt,
            $this->firstAttemptItem($attempt),
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_seq_run',
        );
        $this->attempts()->submit($attempt, $student, 'submit_seq_run');
        $attempt = $this->reloadAttempt($attempt->getId());

        try {
            $this->insertProcessingRunShape($attempt, $fx, 2);
            self::fail('Expected first run_number must be 1.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        $this->insertProcessingRunShape($attempt, $fx, 1);

        try {
            $this->insertProcessingRunShape($attempt, $fx, 3);
            self::fail('Expected jump 1→3 rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        try {
            $this->insertProcessingRunShape($attempt, $fx, 1);
            self::fail('Expected duplicate run_number 1 rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }

        $this->insertProcessingRunShape($attempt, $fx, 2);
        $max = (int) $this->em->getConnection()->fetchOne(
            'SELECT MAX(run_number) FROM assessment_scoring_runs WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertSame(2, $max);
    }

    public function testSequentialReleaseNumberEnforced(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('ashd13');
        $now = $this->utcNow();

        try {
            $this->em->getConnection()->insert('assessment_result_releases', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'scoring_run_id' => $run->getId()->toBinary(),
                'release_number' => 2,
                'status' => ResultReleaseStatus::Released->value,
                'released_by_id' => $fx['owner']->getId()->toBinary(),
                'released_at' => $now,
                'withdrawn_by_id' => null,
                'withdrawn_at' => null,
                'reason_code' => 'rel_jump',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected first release_number must be 1.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'rel_1',
        );

        $regrade = $this->scoring()->regradeAttempt(
            $this->reloadAttempt($attempt->getId()),
            null,
            'regrade_seq_rel',
        );
        $active = $this->resultReleases()->findActiveReleasedForAttempt($attempt->getId());
        self::assertNotNull($active);
        $this->releases()->withdraw(
            $this->reloadRelease($active->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'wd_1',
        );

        try {
            $this->em->getConnection()->insert('assessment_result_releases', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'scoring_run_id' => $regrade->getId()->toBinary(),
                'release_number' => 3,
                'status' => ResultReleaseStatus::Released->value,
                'released_by_id' => $fx['owner']->getId()->toBinary(),
                'released_at' => $now,
                'withdrawn_by_id' => null,
                'withdrawn_at' => null,
                'reason_code' => 'rel_jump3',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected jump 1→3 release_number rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        try {
            $this->em->getConnection()->insert('assessment_result_releases', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'scoring_run_id' => $regrade->getId()->toBinary(),
                'release_number' => 1,
                'status' => ResultReleaseStatus::Released->value,
                'released_by_id' => $fx['owner']->getId()->toBinary(),
                'released_at' => $now,
                'withdrawn_by_id' => null,
                'withdrawn_at' => null,
                'reason_code' => 'rel_dup1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected duplicate release_number 1 rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }

        $this->releases()->release(
            $this->reloadScoringRun($regrade->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'rel_2',
        );
        $max = (int) $this->em->getConnection()->fetchOne(
            'SELECT MAX(release_number) FROM assessment_result_releases WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertSame(2, $max);
    }

    public function testSequentialDecisionNumberEnforced(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('ashd14');
        $owner = $this->reloadUser($fx['owner']->getId());
        $now = $this->utcNow();
        $base = [
            'scoring_run_id' => $run->getId()->toBinary(),
            'attempt_id' => $attempt->getId()->toBinary(),
            'attempt_item_id' => $item->getId()->toBinary(),
            'outcome' => ItemScoreOutcome::ManuallyGraded->value,
            'awarded_points' => '1.00',
            'maximum_points' => '2.50',
            'evaluator_user_id' => $owner->getId()->toBinary(),
            'evaluated_at' => $now,
            'reason_code' => 'dec_seq',
            'created_at' => $now,
        ];

        try {
            $this->em->getConnection()->insert('assessment_manual_grade_decisions', $base + [
                'id' => Uuid::v7()->toBinary(),
                'decision_number' => 2,
            ]);
            self::fail('Expected first decision_number must be 1.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        $this->em->getConnection()->insert('assessment_manual_grade_decisions', $base + [
            'id' => Uuid::v7()->toBinary(),
            'decision_number' => 1,
            'reason_code' => 'dec_seq_1',
        ]);

        try {
            $this->em->getConnection()->insert('assessment_manual_grade_decisions', $base + [
                'id' => Uuid::v7()->toBinary(),
                'decision_number' => 3,
                'reason_code' => 'dec_seq_3',
            ]);
            self::fail('Expected jump 1→3 decision_number rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        try {
            $this->em->getConnection()->insert('assessment_manual_grade_decisions', $base + [
                'id' => Uuid::v7()->toBinary(),
                'decision_number' => 1,
                'reason_code' => 'dec_seq_dup',
            ]);
            self::fail('Expected duplicate decision_number 1 rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }

        $this->em->getConnection()->insert('assessment_manual_grade_decisions', $base + [
            'id' => Uuid::v7()->toBinary(),
            'decision_number' => 2,
            'awarded_points' => '2.00',
            'reason_code' => 'dec_seq_2',
        ]);
        $max = (int) $this->em->getConnection()->fetchOne(
            'SELECT MAX(decision_number) FROM assessment_manual_grade_decisions
              WHERE scoring_run_id = ? AND attempt_item_id = ?',
            [$run->getId()->toBinary(), $item->getId()->toBinary()],
        );
        self::assertSame(2, $max);
    }

    public function testDecisionUpdateAndDeleteRejected(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('ashd15');
        $owner = $this->reloadUser($fx['owner']->getId());
        $now = $this->utcNow();
        $id = Uuid::v7();
        $this->em->getConnection()->insert('assessment_manual_grade_decisions', [
            'id' => $id->toBinary(),
            'scoring_run_id' => $run->getId()->toBinary(),
            'attempt_id' => $attempt->getId()->toBinary(),
            'attempt_item_id' => $item->getId()->toBinary(),
            'decision_number' => 1,
            'outcome' => ItemScoreOutcome::ManuallyGraded->value,
            'awarded_points' => '1.50',
            'maximum_points' => '2.50',
            'evaluator_user_id' => $owner->getId()->toBinary(),
            'evaluated_at' => $now,
            'reason_code' => 'dec_immutable',
            'created_at' => $now,
        ]);

        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE assessment_manual_grade_decisions SET awarded_points = ? WHERE id = ?',
                ['1.00', $id->toBinary()],
            );
            self::fail('Expected decision UPDATE rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM assessment_manual_grade_decisions WHERE id = ?',
                [$id->toBinary()],
            );
            self::fail('Expected decision DELETE rejected.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    /**
     * @param array<string, mixed> $fx
     * @param array<string, mixed> $overrides
     */
    private function insertProcessingRunShape(
        \App\Entity\AssessmentAttempt $attempt,
        array $fx,
        int $runNumber,
        array $overrides = [],
    ): Uuid {
        $now = $this->utcNow();
        $id = Uuid::v7();
        $row = [
            'id' => $id->toBinary(),
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
            'run_number' => $runNumber,
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
            'reason_code' => 'ashd_proc',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach ($overrides as $key => $value) {
            $row[$key] = $value;
        }
        $this->em->getConnection()->insert('assessment_scoring_runs', $row);

        return $id;
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function insertAutomaticCorrectItemScore(
        Uuid $runId,
        \App\Entity\AssessmentAttempt $attempt,
        \App\Entity\AssessmentAttemptItem $item,
        array $fx,
    ): void {
        $now = $this->utcNow();
        $this->em->getConnection()->insert('assessment_item_scores', [
            'id' => Uuid::v7()->toBinary(),
            'scoring_run_id' => $runId->toBinary(),
            'attempt_id' => $attempt->getId()->toBinary(),
            'attempt_item_id' => $item->getId()->toBinary(),
            'assessment_revision_id' => $fx['publication']->getAssessmentRevision()->getId()->toBinary(),
            'question_id' => $item->getQuestion()->getId()->toBinary(),
            'question_revision_id' => $item->getQuestionRevision()->getId()->toBinary(),
            'scoring_method' => ScoringMethod::Automatic->value,
            'outcome' => ItemScoreOutcome::Correct->value,
            'maximum_points' => '2.50',
            'awarded_points' => '2.50',
            'penalty_points_applied' => '0.00',
            'manual_pending' => 0,
            'evaluator_user_id' => null,
            'evaluated_at' => null,
            'reason_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
