<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentScoringFailureReason;
use App\Enum\ScoringRunStatus;
use App\Enum\SecurityAuditAction;
use App\Exception\AssessmentScoringException;
use App\Tests\Support\AssessmentScoringTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentScoringManagerTest extends KernelTestCase
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

    public function testSubmittedAttemptScoresCompleted(): void
    {
        [$attempt, $run] = $this->submitAndScoreClassroomAttempt('asm1');
        self::assertSame(ScoringRunStatus::Completed, $run->getStatus());
        self::assertSame(1, $run->getRunNumber());
        self::assertSame('2.50', $run->getFinalPoints());
        self::assertSame('2.50', $run->getMaximumPoints());
        self::assertSame(1, $run->getCorrectCount());
        self::assertGreaterThan(0, $this->events->countByAction(SecurityAuditAction::AssessmentScoringStarted->value));
        self::assertGreaterThan(0, $this->events->countByAction(SecurityAuditAction::AssessmentScoringCompleted->value));
        unset($attempt);
    }

    public function testExpiredAttemptIsScoreable(): void
    {
        $mock = new MockClock();
        Clock::set($mock);
        $fx = $this->activatedClassroomDelivery('asm2');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_exp',
        );
        $this->attempts()->saveAnswer(
            $attempt,
            $this->firstAttemptItem($attempt),
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_exp',
        );
        $mock->modify('+2 hours');
        $this->attempts()->expire($attempt, 'system_expire');
        $attempt = $this->reloadAttempt($attempt->getId());

        $run = $this->scoring()->scoreAttempt($attempt, null, 'score_expired');
        self::assertSame(ScoringRunStatus::Completed, $run->getStatus());
    }

    public function testInProgressAndCancelledNotScoreable(): void
    {
        $fx = $this->activatedClassroomDelivery('asm3');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_ip',
        );
        try {
            $this->scoring()->scoreAttempt($attempt, null, 'score_ip');
            self::fail('Expected in_progress not scoreable.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::AttemptNotScorable, $e->getReason());
        }

        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        $fx = $this->activatedClassroomDelivery('asm3b');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_can',
        );
        $this->attempts()->cancel(
            $attempt,
            $this->reloadUser($fx['owner']->getId()),
            'cancel_asm',
            'admin_cancel',
        );
        $attempt = $this->reloadAttempt($attempt->getId());
        try {
            $this->scoring()->scoreAttempt($attempt, null, 'score_can');
            self::fail('Expected cancelled not scoreable.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::AttemptNotScorable, $e->getReason());
        }
    }

    public function testPendingManualRunCanBeMaterializedForGrading(): void
    {
        [, $run] = $this->submitAndScorePendingManual('asm4');
        self::assertSame(ScoringRunStatus::PendingManual, $run->getStatus());
        self::assertGreaterThanOrEqual(1, $run->getManualPendingCount());
    }

    public function testIdempotentScoreSameReasonCodeReturnsSameRun(): void
    {
        [$attempt, $first] = $this->submitAndScoreClassroomAttempt('asm5');
        $second = $this->scoring()->scoreAttempt(
            $this->reloadAttempt($attempt->getId()),
            null,
            'score_asm5',
        );
        self::assertTrue($first->getId()->equals($second->getId()));
        self::assertSame(1, $second->getRunNumber());
        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_scoring_runs WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertSame(1, $count);
    }

    public function testRegradeCreatesNewRunNumber(): void
    {
        [$attempt, $first] = $this->submitAndScoreClassroomAttempt('asm6');
        $regrade = $this->scoring()->regradeAttempt(
            $this->reloadAttempt($attempt->getId()),
            null,
            'regrade_asm6',
        );
        self::assertFalse($first->getId()->equals($regrade->getId()));
        self::assertSame(2, $regrade->getRunNumber());
        self::assertSame(ScoringRunStatus::Completed, $regrade->getStatus());
        self::assertGreaterThan(0, $this->events->countByAction(SecurityAuditAction::AssessmentRegraded->value));
    }

    public function testDecryptFailureRollsBackWithoutHalfScores(): void
    {
        $fx = $this->activatedClassroomDelivery('asm8');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_dec',
        );
        $item = $this->firstAttemptItem($attempt);
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_dec',
        );

        $tampered = $answer->getAnswerCiphertext();
        $tampered[0] = "\0" === $tampered[0] ? "\1" : "\0";
        $conn = $this->em->getConnection();
        $answeredAt = (string) $conn->fetchOne(
            'SELECT answered_at FROM assessment_attempt_answers WHERE id = ?',
            [$answer->getId()->toBinary()],
        );
        $later = (new \DateTimeImmutable($answeredAt))->modify('+1 second')->format('Y-m-d H:i:s');
        $conn->update('assessment_attempt_answers', [
            'answer_ciphertext' => $tampered,
            'answer_nonce' => random_bytes(24),
            'client_revision' => $answer->getClientRevision() + 1,
            'answered_at' => $later,
            'updated_at' => $later,
        ], ['id' => $answer->getId()->toBinary()]);

        $this->attempts()->submit($this->reloadAttempt($attempt->getId()), $student, 'submit_dec');
        $this->em->clear();

        try {
            $this->scoring()->scoreAttempt($this->reloadAttempt($attempt->getId()), null, 'score_dec');
            self::fail('Expected decryption failure.');
        } catch (AssessmentScoringException $e) {
            self::assertContains(
                $e->getReason(),
                [
                    AssessmentScoringFailureReason::AnswerDecryptionFailed,
                    AssessmentScoringFailureReason::AnswerIntegrityFailed,
                ],
            );
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_scoring_runs WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        ));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_item_scores WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        ));
    }
}
