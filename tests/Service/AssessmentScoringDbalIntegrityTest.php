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

final class AssessmentScoringDbalIntegrityTest extends KernelTestCase
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

    public function testDuplicateRunNumberDenied(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('asdi1');
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        try {
            $this->em->getConnection()->insert('assessment_scoring_runs', [
                'id' => Uuid::v7()->toBinary(),
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
                'run_number' => $run->getRunNumber(),
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
                'reason_code' => 'dup_run',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected duplicate run_number denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testDuplicateItemScoreDenied(): void
    {
        [$attempt, $run] = $this->submitAndScoreClassroomAttempt('asdi2');
        $item = $this->firstAttemptItem($attempt);
        $score = $this->itemScores()->findForRunAndItem($run->getId(), $item->getId());
        self::assertNotNull($score);

        // Completed run blocks item insert; use a fresh processing run.
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        [$attempt, $completed, $fx] = $this->submitAndScoreClassroomAttempt('asdi2b');
        unset($completed);
        $item = $this->firstAttemptItem($attempt);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $procId = Uuid::v7();
        $this->em->getConnection()->insert('assessment_scoring_runs', [
            'id' => $procId->toBinary(),
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
            'run_number' => 2,
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
            'reason_code' => 'proc_dup',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = [
            'id' => Uuid::v7()->toBinary(),
            'scoring_run_id' => $procId->toBinary(),
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
        ];
        $this->em->getConnection()->insert('assessment_item_scores', $row);
        try {
            $row['id'] = Uuid::v7()->toBinary();
            $this->em->getConnection()->insert('assessment_item_scores', $row);
            self::fail('Expected duplicate item score denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testCompletedUpdateAndDeleteBlocked(): void
    {
        [, $run] = $this->submitAndScoreClassroomAttempt('asdi3');
        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE assessment_scoring_runs SET final_points = ? WHERE id = ?',
                ['0.00', $run->getId()->toBinary()],
            );
            self::fail('Expected completed UPDATE blocked.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM assessment_scoring_runs WHERE id = ?',
                [$run->getId()->toBinary()],
            );
            self::fail('Expected completed DELETE blocked.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testSecondActiveReleaseDenied(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('asdi4');
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'rel_1',
        );
        $regrade = $this->scoring()->regradeAttempt(
            $this->reloadAttempt($attempt->getId()),
            null,
            'regrade_asdi4',
        );
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        try {
            $this->em->getConnection()->insert('assessment_result_releases', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'scoring_run_id' => $regrade->getId()->toBinary(),
                'release_number' => 2,
                'status' => ResultReleaseStatus::Released->value,
                'released_by_id' => $fx['owner']->getId()->toBinary(),
                'released_at' => $now,
                'withdrawn_by_id' => null,
                'withdrawn_at' => null,
                'reason_code' => 'second_active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected second active release denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }
    }

    public function testCheckConstraintsExist(): void
    {
        $names = $this->em->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT constraint_name FROM information_schema.check_constraints
            WHERE constraint_schema = DATABASE()
              AND (
                    constraint_name LIKE 'chk_asr_%'
                 OR constraint_name LIKE 'chk_ais_%'
                 OR constraint_name LIKE 'chk_amgd_%'
                 OR constraint_name LIKE 'chk_arr_%'
              )
            SQL);
        self::assertContains('chk_asr_status', $names);
        self::assertContains('chk_ais_outcome', $names);
        self::assertContains('chk_amgd_awarded', $names);
        self::assertContains('chk_arr_lifecycle', $names);
    }

    public function testInformationSchemaFkUniqueAndTriggers(): void
    {
        $fk = (int) $this->em->getConnection()->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE constraint_schema = DATABASE()
              AND table_name IN (
                    'assessment_scoring_runs',
                    'assessment_item_scores',
                    'assessment_manual_grade_decisions',
                    'assessment_result_releases',
                    'assessment_result_active_release_guards'
              )
              AND constraint_type = 'FOREIGN KEY'
            SQL);
        self::assertGreaterThan(10, $fk);

        $unique = (int) $this->em->getConnection()->fetchOne(<<<'SQL'
            SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'assessment_scoring_runs'
              AND index_name = 'uniq_asr_attempt_run'
              AND non_unique = 0
            SQL);
        self::assertSame(1, $unique);

        $triggers = $this->em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT TRIGGER_NAME, ACTION_STATEMENT
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND (
                    TRIGGER_NAME LIKE 'trg_assessment_scoring%'
                 OR TRIGGER_NAME LIKE 'trg_assessment_item_scores%'
                 OR TRIGGER_NAME LIKE 'trg_assessment_manual_grade%'
                 OR TRIGGER_NAME LIKE 'trg_assessment_result_%'
              )
            ORDER BY TRIGGER_NAME
            SQL);
        self::assertNotEmpty($triggers);
        foreach ($triggers as $row) {
            $body = (string) $row['ACTION_STATEMENT'];
            self::assertStringNotContainsStringIgnoringCase('@testlig', $body);
            self::assertStringNotContainsStringIgnoringCase('bypass', $body);
            self::assertStringNotContainsStringIgnoringCase('FOREIGN_KEY_CHECKS', $body);
            self::assertStringNotContainsStringIgnoringCase('test-only', $body);
        }
    }

    public function testCrossAttemptItemScoreDenied(): void
    {
        [$attemptA, , $fxA] = $this->submitAndScoreClassroomAttempt('asdi5a');
        [$attemptB, , $fxB] = $this->submitAndScoreClassroomAttempt('asdi5b');
        $itemA = $this->firstAttemptItem($attemptA);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $procId = Uuid::v7();
        $this->em->getConnection()->insert('assessment_scoring_runs', [
            'id' => $procId->toBinary(),
            'attempt_id' => $attemptB->getId()->toBinary(),
            'institution_id' => $fxB['institution']->getId()->toBinary(),
            'delivery_id' => $fxB['delivery']->getId()->toBinary(),
            'recipient_id' => $fxB['recipient']->getId()->toBinary(),
            'user_id' => $fxB['student']->getId()->toBinary(),
            'assessment_id' => $fxB['assessment']->getId()->toBinary(),
            'assessment_publication_id' => $fxB['publication']->getId()->toBinary(),
            'assessment_revision_id' => $fxB['publication']->getAssessmentRevision()->getId()->toBinary(),
            'publication_number' => $fxB['publication']->getPublicationNumber(),
            'scoring_policy_id' => 'testlig_default_v1',
            'scoring_version' => 1,
            'run_number' => 2,
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
            'reason_code' => 'cross_item',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $this->em->getConnection()->insert('assessment_item_scores', [
                'id' => Uuid::v7()->toBinary(),
                'scoring_run_id' => $procId->toBinary(),
                'attempt_id' => $attemptB->getId()->toBinary(),
                'attempt_item_id' => $itemA->getId()->toBinary(),
                'assessment_revision_id' => $fxB['publication']->getAssessmentRevision()->getId()->toBinary(),
                'question_id' => $itemA->getQuestion()->getId()->toBinary(),
                'question_revision_id' => $itemA->getQuestionRevision()->getId()->toBinary(),
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
            self::fail('Expected cross-attempt item score denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }
        unset($fxA);
    }
}
