<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentItemScore;
use App\Entity\AssessmentManualGradeDecision;
use App\Entity\AssessmentScoringRun;
use App\Entity\User;
use App\Enum\ScoringRunStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentItemScoreRepository;
use App\Repository\AssessmentManualGradeDecisionRepository;
use App\Scoring\DecimalScoreCalculator;
use App\Scoring\ScoringContentPolicy;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manual grading for pending_manual scoring runs.
 *
 * Lock order: AssessmentScoringRun WRITE → item score → decision append → audit.
 */
final class ManualAssessmentGradingManager
{
    public function __construct(
        private readonly AssessmentItemScoreRepository $itemScores,
        private readonly AssessmentManualGradeDecisionRepository $decisions,
        private readonly DecimalScoreCalculator $scoreCalculator,
        private readonly ScoringContentPolicy $contentPolicy,
        private readonly AssessmentResultAccessGate $accessGate,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function gradeItem(
        AssessmentScoringRun $run,
        AssessmentAttemptItem $item,
        User $actor,
        string $awardedPoints,
        string $reasonCode,
    ): AssessmentScoringRun {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $runId = $run->getId();
        $itemId = $item->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $runId,
                $itemId,
                $actorId,
                $awardedPoints,
                $reasonCode,
            ): AssessmentScoringRun {
                $lockedRun = $this->findFreshRun($runId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedRun instanceof AssessmentScoringRun) {
                    throw AssessmentScoringException::notFound();
                }
                if (ScoringRunStatus::PendingManual !== $lockedRun->getStatus()) {
                    throw AssessmentScoringException::manualGradeNotAllowed();
                }

                $itemScore = $this->itemScores->findForRunAndItem($lockedRun->getId(), $itemId);
                if (!$itemScore instanceof AssessmentItemScore) {
                    throw AssessmentScoringException::itemNotFound();
                }
                if (!$itemScore->isManualPending()) {
                    throw AssessmentScoringException::manualGradeNotAllowed();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User
                    || !$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
                ) {
                    throw AssessmentScoringException::unauthorized();
                }

                $this->accessGate->assertCanManuallyGrade($freshActor, $lockedRun->getAttempt());

                $normalizedAwarded = $this->normalizeAwardedPoints(
                    $awardedPoints,
                    $itemScore->getMaximumPoints(),
                );

                $now = $this->utcNow();
                $decisionNumber = $this->decisions->findMaxDecisionNumber($itemId) + 1;
                $decision = AssessmentManualGradeDecision::record(
                    $lockedRun,
                    $itemScore->getAttemptItem(),
                    $decisionNumber,
                    $normalizedAwarded,
                    $itemScore->getMaximumPoints(),
                    $freshActor,
                    $reasonCode,
                    $now,
                );
                $this->decisions->save($decision, false);

                $itemScore->applyManualGrade($normalizedAwarded, $freshActor, $reasonCode, $now);

                // Flush item/decision while run is still pending_manual. Completing the run in the
                // same UoW would make AssessmentScoringImmutabilityListener see a terminal status.
                $this->entityManager->flush();

                $allScores = $this->itemScores->findAllForRun($lockedRun->getId());
                $aggregateInputs = [];
                foreach ($allScores as $score) {
                    $aggregateInputs[] = [
                        'awardedPoints' => $score->getAwardedPoints(),
                        'maximumPoints' => $score->getMaximumPoints(),
                        'outcome' => $score->getOutcome(),
                    ];
                }
                $aggregates = $this->scoreCalculator->calculate($aggregateInputs);

                if (0 === $aggregates['manualPendingCount']) {
                    $lockedRun->complete(
                        $aggregates['rawPoints'],
                        $aggregates['finalPoints'],
                        $aggregates['maximumPoints'],
                        $aggregates['percentage'],
                        $aggregates['correctCount'],
                        $aggregates['incorrectCount'],
                        $aggregates['unansweredCount'],
                        $aggregates['manualPendingCount'],
                        $now,
                    );
                } else {
                    $lockedRun->refreshPendingManualAggregates(
                        $aggregates['rawPoints'],
                        $aggregates['finalPoints'],
                        $aggregates['maximumPoints'],
                        $aggregates['percentage'],
                        $aggregates['correctCount'],
                        $aggregates['incorrectCount'],
                        $aggregates['unansweredCount'],
                        $aggregates['manualPendingCount'],
                        $now,
                    );
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentItemManuallyGraded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $lockedRun->getUser(),
                    metadata: [
                        'source' => 'manual_assessment_grading_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedRun->getAttempt()->getId()->toRfc4122(),
                        'attempt_item_id' => $itemId->toRfc4122(),
                        'scoring_run_id' => $lockedRun->getId()->toRfc4122(),
                        'run_number' => $lockedRun->getRunNumber(),
                        'status' => $lockedRun->getStatus()->value,
                        'manual_pending_count' => $aggregates['manualPendingCount'],
                    ],
                    captureRequestHashes: false,
                ), false);

                if (ScoringRunStatus::Completed === $lockedRun->getStatus()) {
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::AssessmentScoringCompleted,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Success,
                        actorUser: $freshActor,
                        subjectUser: $lockedRun->getUser(),
                        metadata: [
                            'source' => 'manual_assessment_grading_manager',
                            'reason_code' => $reasonCode,
                            'attempt_id' => $lockedRun->getAttempt()->getId()->toRfc4122(),
                            'scoring_run_id' => $lockedRun->getId()->toRfc4122(),
                            'run_number' => $lockedRun->getRunNumber(),
                            'scoring_version' => $lockedRun->getScoringVersion(),
                            'status' => $lockedRun->getStatus()->value,
                            'correct_count' => $aggregates['correctCount'],
                            'incorrect_count' => $aggregates['incorrectCount'],
                            'unanswered_count' => $aggregates['unansweredCount'],
                            'manual_pending_count' => 0,
                        ],
                        captureRequestHashes: false,
                    ), false);
                }

                $this->entityManager->flush();

                return $lockedRun;
            });
        } catch (AssessmentScoringException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentScoringException::conflict();
        } catch (\Throwable $e) {
            if ($e instanceof DriverException) {
                throw AssessmentScoringException::conflict();
            }
            throw $e;
        }
    }

    /**
     * @return numeric-string
     */
    private function normalizeAwardedPoints(string $awardedPoints, string $maximumPoints): string
    {
        $normalized = $this->scoreCalculator->normalizePoints($awardedPoints);
        $maximum = $this->scoreCalculator->normalizePoints($maximumPoints);
        if (-1 === bccomp($normalized, '0', DecimalScoreCalculator::POINTS_SCALE)) {
            throw AssessmentScoringException::invalidInput('Manual awardedPoints must be >= 0.');
        }
        if (1 === bccomp($normalized, $maximum, DecimalScoreCalculator::POINTS_SCALE)) {
            throw AssessmentScoringException::invalidInput('Manual awardedPoints must not exceed maximumPoints.');
        }

        return $normalized;
    }

    private function findFreshRun(Uuid $id, LockMode $lockMode): ?AssessmentScoringRun
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentScoringRun::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentScoringRun ? $result : null;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
