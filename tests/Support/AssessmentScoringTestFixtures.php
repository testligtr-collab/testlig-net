<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentItemScore;
use App\Entity\AssessmentResultRelease;
use App\Entity\AssessmentScoringRun;
use App\Enum\ItemScoreOutcome;
use App\Enum\ScoringMethod;
use App\Enum\ScoringRunStatus;
use App\Repository\AssessmentItemScoreRepository;
use App\Repository\AssessmentManualGradeDecisionRepository;
use App\Repository\AssessmentResultReleaseRepository;
use App\Repository\AssessmentScoringRunRepository;
use App\Service\AssessmentResultReader;
use App\Service\AssessmentResultReleaseManager;
use App\Service\AssessmentScoringManager;
use App\Service\ManualAssessmentGradingManager;
use Symfony\Component\Uid\Uuid;

/**
 * Shared helpers for Stage 2.12 assessment scoring / result tests.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait AssessmentScoringTestFixtures
{
    use AssessmentAttemptTestFixtures;

    private function scoring(): AssessmentScoringManager
    {
        $s = static::getContainer()->get(AssessmentScoringManager::class);
        self::assertInstanceOf(AssessmentScoringManager::class, $s);

        return $s;
    }

    private function manualGrading(): ManualAssessmentGradingManager
    {
        $s = static::getContainer()->get(ManualAssessmentGradingManager::class);
        self::assertInstanceOf(ManualAssessmentGradingManager::class, $s);

        return $s;
    }

    private function releases(): AssessmentResultReleaseManager
    {
        $s = static::getContainer()->get(AssessmentResultReleaseManager::class);
        self::assertInstanceOf(AssessmentResultReleaseManager::class, $s);

        return $s;
    }

    private function resultReader(): AssessmentResultReader
    {
        $s = static::getContainer()->get(AssessmentResultReader::class);
        self::assertInstanceOf(AssessmentResultReader::class, $s);

        return $s;
    }

    private function scoringRuns(): AssessmentScoringRunRepository
    {
        $s = static::getContainer()->get(AssessmentScoringRunRepository::class);
        self::assertInstanceOf(AssessmentScoringRunRepository::class, $s);

        return $s;
    }

    private function itemScores(): AssessmentItemScoreRepository
    {
        $s = static::getContainer()->get(AssessmentItemScoreRepository::class);
        self::assertInstanceOf(AssessmentItemScoreRepository::class, $s);

        return $s;
    }

    private function gradeDecisions(): AssessmentManualGradeDecisionRepository
    {
        $s = static::getContainer()->get(AssessmentManualGradeDecisionRepository::class);
        self::assertInstanceOf(AssessmentManualGradeDecisionRepository::class, $s);

        return $s;
    }

    private function resultReleases(): AssessmentResultReleaseRepository
    {
        $s = static::getContainer()->get(AssessmentResultReleaseRepository::class);
        self::assertInstanceOf(AssessmentResultReleaseRepository::class, $s);

        return $s;
    }

    /**
     * Submit a correct single-choice answer (opt_b) and score the attempt.
     *
     * @return array{0: AssessmentAttempt, 1: AssessmentScoringRun, 2: array<string, mixed>}
     */
    private function submitAndScoreClassroomAttempt(string $prefix, ?\App\Entity\User $actor = null): array
    {
        $fx = $this->activatedClassroomDelivery($prefix);
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_sc_'.$prefix);
        $item = $this->firstAttemptItem($attempt);
        $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_sc_'.$prefix,
        );
        $this->attempts()->submit($attempt, $student, 'submit_sc_'.$prefix);
        $attempt = $this->reloadAttempt($attempt->getId());
        $run = $this->scoring()->scoreAttempt($attempt, $actor, 'score_'.$prefix);
        self::assertSame(ScoringRunStatus::Completed, $run->getStatus());

        return [$attempt, $run, $fx];
    }

    /**
     * Submit attempt then materialize a pending_manual scoring run via DBAL.
     * (question_answer_keys are append-only; empty acceptedAnswers cannot be forced.).
     *
     * @return array{0: AssessmentAttempt, 1: AssessmentScoringRun, 2: array<string, mixed>, 3: AssessmentAttemptItem}
     */
    private function submitAndScorePendingManual(string $prefix): array
    {
        $fx = $this->activatedClassroomDelivery($prefix);
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_pm_'.$prefix);
        $item = $this->firstAttemptItem($attempt);
        $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_pm_'.$prefix,
        );
        $this->attempts()->submit($attempt, $student, 'submit_pm_'.$prefix);
        $attempt = $this->reloadAttempt($attempt->getId());
        $item = $this->firstAttemptItem($attempt);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $runId = Uuid::v7();
        $conn = $this->em->getConnection();
        $conn->insert('assessment_scoring_runs', [
            'id' => $runId->toBinary(),
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
            'run_number' => 1,
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
            'reason_code' => 'score_pm_'.$prefix,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $conn->insert('assessment_item_scores', [
            'id' => Uuid::v7()->toBinary(),
            'scoring_run_id' => $runId->toBinary(),
            'attempt_id' => $attempt->getId()->toBinary(),
            'attempt_item_id' => $item->getId()->toBinary(),
            'assessment_revision_id' => $fx['publication']->getAssessmentRevision()->getId()->toBinary(),
            'question_id' => $item->getQuestion()->getId()->toBinary(),
            'question_revision_id' => $item->getQuestionRevision()->getId()->toBinary(),
            'scoring_method' => ScoringMethod::Manual->value,
            'outcome' => ItemScoreOutcome::ManualPending->value,
            'maximum_points' => '2.50',
            'awarded_points' => '0.00',
            'penalty_points_applied' => '0.00',
            'manual_pending' => 1,
            'evaluator_user_id' => null,
            'evaluated_at' => null,
            'reason_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $conn->executeStatement(
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
            [$now, $runId->toBinary()],
        );
        $this->em->clear();

        $run = $this->reloadScoringRun($runId);
        self::assertSame(ScoringRunStatus::PendingManual, $run->getStatus());
        $item = $this->em->find(AssessmentAttemptItem::class, $item->getId());
        self::assertInstanceOf(AssessmentAttemptItem::class, $item);

        return [$this->reloadAttempt($attempt->getId()), $run, $fx, $item];
    }

    private function reloadScoringRun(Uuid $id): AssessmentScoringRun
    {
        $run = $this->em->find(AssessmentScoringRun::class, $id);
        self::assertInstanceOf(AssessmentScoringRun::class, $run);

        return $run;
    }

    private function reloadRelease(Uuid $id): AssessmentResultRelease
    {
        $release = $this->em->find(AssessmentResultRelease::class, $id);
        self::assertInstanceOf(AssessmentResultRelease::class, $release);

        return $release;
    }

    private function reloadItemScore(Uuid $id): AssessmentItemScore
    {
        $score = $this->em->find(AssessmentItemScore::class, $id);
        self::assertInstanceOf(AssessmentItemScore::class, $score);

        return $score;
    }

    private function sqlState(\Throwable $e): ?string
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof \Doctrine\DBAL\Driver\Exception) {
                return $current->getSQLState();
            }
        }

        return null;
    }

    private function blobToString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_resource($value)) {
            $contents = stream_get_contents($value);
            self::assertNotFalse($contents);

            return $contents;
        }

        self::fail('Unexpected blob type.');
    }
}
