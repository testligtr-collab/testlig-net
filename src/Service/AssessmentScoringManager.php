<?php

declare(strict_types=1);

namespace App\Service;

use App\Assessment\AssessmentPublicationIntegrityVerifier;
use App\Attempt\Answer\AttemptAnswerEncryptor;
use App\Dto\SecurityAuditContext;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptAnswer;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentItemScore;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\AssessmentScoringRun;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\QuestionAnswerKey;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\ScoringRunStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserStatus;
use App\Exception\AssessmentAttemptException;
use App\Exception\AssessmentException;
use App\Exception\AssessmentScoringException;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionAnswerIntegrityHasher;
use App\Repository\AssessmentAttemptAnswerRepository;
use App\Repository\AssessmentAttemptItemRepository;
use App\Repository\AssessmentAttemptRepository;
use App\Repository\AssessmentItemScoreRepository;
use App\Repository\AssessmentScoringRunRepository;
use App\Repository\QuestionAnswerKeyRepository;
use App\Scoring\AutomaticAnswerEvaluator;
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
 * Assessment automatic scoring / regrade orchestration.
 *
 * Global lock order (aligned with AssessmentAttemptManager; UUID sets ASC):
 * 1. AssessmentDelivery READ
 * 2. AssessmentDeliveryRecipient READ
 * 3. Institution / User / Membership READ (fresh)
 * 4. AssessmentAttempt WRITE + HINT_REFRESH
 * 5. Assessment / AssessmentPublication / AssessmentRevision integrity verify
 * 6. AssessmentScoringRun create + AssessmentItemScore persist
 * 7. Audit in the same transaction
 */
final class AssessmentScoringManager
{
    public function __construct(
        private readonly AssessmentAttemptRepository $attempts,
        private readonly AssessmentAttemptItemRepository $attemptItems,
        private readonly AssessmentAttemptAnswerRepository $answers,
        private readonly AssessmentScoringRunRepository $scoringRuns,
        private readonly AssessmentItemScoreRepository $itemScores,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly AutomaticAnswerEvaluator $evaluator,
        private readonly DecimalScoreCalculator $scoreCalculator,
        private readonly AttemptAnswerEncryptor $answerEncryptor,
        private readonly QuestionAnswerIntegrityHasher $answerIntegrityHasher,
        private readonly AssessmentPublicationIntegrityVerifier $publicationIntegrityVerifier,
        private readonly ScoringContentPolicy $contentPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function scoreAttempt(
        AssessmentAttempt $attempt,
        ?User $actor,
        string $reasonCode,
    ): AssessmentScoringRun {
        return $this->runScoring($attempt, $actor, $reasonCode, regrade: false);
    }

    public function regradeAttempt(
        AssessmentAttempt $attempt,
        ?User $actor,
        string $reasonCode,
    ): AssessmentScoringRun {
        return $this->runScoring($attempt, $actor, $reasonCode, regrade: true);
    }

    private function runScoring(
        AssessmentAttempt $attempt,
        ?User $actor,
        string $reasonCode,
        bool $regrade,
    ): AssessmentScoringRun {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $attemptId = $attempt->getId();
        $actorId = $actor?->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $attemptId,
                $actorId,
                $reasonCode,
                $regrade,
            ): AssessmentScoringRun {
                // Locksless snapshot for parent ids — do not trust identity-map for decisions.
                $snapshot = $this->attempts->findOneById($attemptId);
                if (!$snapshot instanceof AssessmentAttempt) {
                    throw AssessmentScoringException::notFound();
                }

                $delivery = $this->findFreshDelivery($snapshot->getDelivery()->getId(), LockMode::PESSIMISTIC_READ);
                if (!$delivery instanceof AssessmentDelivery) {
                    throw AssessmentScoringException::notFound();
                }
                $recipient = $this->findFreshRecipient($snapshot->getRecipient()->getId(), LockMode::PESSIMISTIC_READ);
                if (!$recipient instanceof AssessmentDeliveryRecipient) {
                    throw AssessmentScoringException::notFound();
                }
                if (AssessmentDeliveryRecipientStatus::Revoked === $recipient->getStatus()) {
                    throw AssessmentScoringException::attemptNotScorable();
                }

                $this->assertFreshStudentInstitutionMembership($snapshot);

                $lockedAttempt = $this->attempts->findFreshAttempt($attemptId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedAttempt instanceof AssessmentAttempt) {
                    throw AssessmentScoringException::notFound();
                }
                if (!$lockedAttempt->getDelivery()->getId()->equals($delivery->getId())
                    || !$lockedAttempt->getRecipient()->getId()->equals($recipient->getId())
                ) {
                    throw AssessmentScoringException::scopeMismatch();
                }

                $status = $lockedAttempt->getStatus();
                if (AssessmentAttemptStatus::Submitted !== $status
                    && AssessmentAttemptStatus::Expired !== $status
                ) {
                    throw AssessmentScoringException::attemptNotScorable();
                }

                $publication = $this->findFreshPublication(
                    $lockedAttempt->getAssessmentPublication()->getId(),
                );
                $assessment = $this->findFreshAssessment($lockedAttempt->getAssessment()->getId());
                $revision = $this->findFreshRevision($lockedAttempt->getAssessmentRevision()->getId());
                if (!$publication instanceof AssessmentPublication
                    || !$assessment instanceof Assessment
                    || !$revision instanceof AssessmentRevision
                ) {
                    throw AssessmentScoringException::publicationIntegrityFailed();
                }
                try {
                    $this->publicationIntegrityVerifier->verify($publication, $assessment, $revision);
                } catch (AssessmentException) {
                    throw AssessmentScoringException::publicationIntegrityFailed();
                }

                $latest = $this->scoringRuns->findLatestForAttempt($lockedAttempt->getId());
                if (!$regrade
                    && $latest instanceof AssessmentScoringRun
                    && $latest->getReasonCode() === $reasonCode
                    && (
                        ScoringRunStatus::Completed === $latest->getStatus()
                        || ScoringRunStatus::PendingManual === $latest->getStatus()
                    )
                ) {
                    return $latest;
                }

                $open = $this->scoringRuns->findOpenForAttempt($lockedAttempt->getId());
                if ($open instanceof AssessmentScoringRun) {
                    if (ScoringRunStatus::Processing === $open->getStatus()) {
                        throw AssessmentScoringException::scoringInProgress();
                    }
                    throw AssessmentScoringException::conflict();
                }

                $actorUser = null;
                if (null !== $actorId) {
                    $users = $this->freshEntities->findFreshLockedUsers(
                        [$actorId],
                        LockMode::PESSIMISTIC_READ,
                    );
                    $actorUser = $users[$actorId->toRfc4122()] ?? null;
                    if (!$actorUser instanceof User
                        || !$this->activeVerifiedUserPolicy->isActiveAndVerified($actorUser)
                    ) {
                        throw AssessmentScoringException::unauthorized();
                    }
                }

                $now = $this->utcNow();
                $runNumber = $this->scoringRuns->findMaxRunNumber($lockedAttempt->getId()) + 1;
                $run = AssessmentScoringRun::createProcessing(
                    $lockedAttempt,
                    $runNumber,
                    $reasonCode,
                    $now,
                    $actorUser,
                    DecimalScoreCalculator::POLICY_ID,
                    AssessmentScoringRun::DEFAULT_SCORING_VERSION,
                );
                $this->scoringRuns->save($run, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $regrade
                        ? SecurityAuditAction::AssessmentRegraded
                        : SecurityAuditAction::AssessmentScoringStarted,
                    actorType: null !== $actorUser
                        ? SecurityAuditActorType::User
                        : SecurityAuditActorType::System,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actorUser,
                    subjectUser: $lockedAttempt->getUser(),
                    metadata: [
                        'source' => 'assessment_scoring_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedAttempt->getId()->toRfc4122(),
                        'scoring_run_id' => $run->getId()->toRfc4122(),
                        'run_number' => $run->getRunNumber(),
                        'scoring_version' => $run->getScoringVersion(),
                        'status' => $run->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                // Persist processing run before item scores so MariaDB BI trigger sees an open run.
                // A single end-of-transaction flush would INSERT the run already completed.
                $this->entityManager->flush();

                $items = $this->attemptItems->findItemsForAttemptOrdered($lockedAttempt->getId());
                if ([] === $items) {
                    throw AssessmentScoringException::invalidInput('Attempt has no scorable items.');
                }

                $answersByItem = $this->indexAnswersByItem(
                    $this->answers->findAllForAttempt($lockedAttempt->getId()),
                );

                $aggregateInputs = [];
                foreach ($items as $item) {
                    $evaluation = $this->evaluateItem($lockedAttempt, $item, $answersByItem);
                    $itemScore = AssessmentItemScore::create(
                        $run,
                        $item,
                        $evaluation->scoringMethod,
                        $evaluation->outcome,
                        $this->scoreCalculator->normalizePoints($item->getPoints()),
                        $evaluation->awardedPoints,
                        $evaluation->penaltyApplied,
                        $evaluation->manualPending,
                        $now,
                    );
                    $this->itemScores->save($itemScore, false);
                    $aggregateInputs[] = [
                        'awardedPoints' => $itemScore->getAwardedPoints(),
                        'maximumPoints' => $itemScore->getMaximumPoints(),
                        'outcome' => $itemScore->getOutcome(),
                    ];
                }

                // Flush item scores while run is still processing (BI trigger + UoW ordering).
                $this->entityManager->flush();

                $aggregates = $this->scoreCalculator->calculate($aggregateInputs);
                if ($aggregates['manualPendingCount'] > 0) {
                    $run->markPendingManual(
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
                    $completedAction = SecurityAuditAction::AssessmentScoringPendingManual;
                } else {
                    $run->complete(
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
                    $completedAction = SecurityAuditAction::AssessmentScoringCompleted;
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $completedAction,
                    actorType: null !== $actorUser
                        ? SecurityAuditActorType::User
                        : SecurityAuditActorType::System,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actorUser,
                    subjectUser: $lockedAttempt->getUser(),
                    metadata: [
                        'source' => 'assessment_scoring_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedAttempt->getId()->toRfc4122(),
                        'scoring_run_id' => $run->getId()->toRfc4122(),
                        'run_number' => $run->getRunNumber(),
                        'scoring_version' => $run->getScoringVersion(),
                        'status' => $run->getStatus()->value,
                        'item_count' => \count($items),
                        'correct_count' => $aggregates['correctCount'],
                        'incorrect_count' => $aggregates['incorrectCount'],
                        'unanswered_count' => $aggregates['unansweredCount'],
                        'manual_pending_count' => $aggregates['manualPendingCount'],
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $run;
            });
        } catch (AssessmentScoringException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentScoringException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }
    }

    /**
     * @param array<string, AssessmentAttemptAnswer> $answersByItem
     */
    private function evaluateItem(
        AssessmentAttempt $attempt,
        AssessmentAttemptItem $item,
        array $answersByItem,
    ): \App\Scoring\ItemEvaluationResult {
        $answer = $answersByItem[$item->getId()->toRfc4122()] ?? null;
        $studentPayload = null;
        if ($answer instanceof AssessmentAttemptAnswer) {
            try {
                $studentPayload = $this->answerEncryptor->decrypt(
                    $answer->getAnswerCiphertext(),
                    $answer->getAnswerNonce(),
                    $answer->getEncryptionVersion(),
                    $attempt->getId()->toRfc4122(),
                    $item->getId()->toRfc4122(),
                    $attempt->getUser()->getId()->toRfc4122(),
                );
            } catch (AssessmentAttemptException) {
                throw AssessmentScoringException::answerDecryptionFailed();
            }
        }

        $questionRevision = $item->getQuestionRevision();
        $answerKey = $this->answerKeys->findOneByRevision($questionRevision);
        if (!$answerKey instanceof QuestionAnswerKey) {
            throw AssessmentScoringException::answerKeyIntegrityFailed();
        }

        try {
            $this->answerIntegrityHasher->verify(
                $answerKey->getAnswerIntegrityHmac(),
                $answerKey->getAnswerPayload(),
                $answerKey->getAnswerType(),
                $questionRevision->getId(),
            );
        } catch (QuestionException) {
            throw AssessmentScoringException::answerKeyIntegrityFailed();
        }

        if ($answerKey->getAnswerType() !== $questionRevision->getType()) {
            throw AssessmentScoringException::answerKeyIntegrityFailed();
        }

        return $this->evaluator->evaluate(
            $questionRevision->getType(),
            $answerKey->getAnswerPayload(),
            $studentPayload,
            $item->getPoints(),
            $item->getPenaltyPoints(),
        );
    }

    private function assertFreshStudentInstitutionMembership(AssessmentAttempt $attempt): void
    {
        $institution = $this->freshEntities->findFreshLockedInstitution(
            $attempt->getInstitution()->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentScoringException::unauthorized();
        }

        $studentId = $attempt->getUser()->getId();
        $users = $this->freshEntities->findFreshLockedUsers([$studentId], LockMode::PESSIMISTIC_READ);
        $freshStudent = $users[$studentId->toRfc4122()] ?? null;
        if (!$freshStudent instanceof User || UserStatus::Active !== $freshStudent->getStatus()) {
            throw AssessmentScoringException::unauthorized();
        }
        if (null === $freshStudent->getEmailVerifiedAt()) {
            throw AssessmentScoringException::unauthorized();
        }

        $membership = $this->freshEntities->findFreshLockedMembership(
            $attempt->getStudentMembership()->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentScoringException::unauthorized();
        }
        if (InstitutionMembershipRole::Student !== $membership->getRole()) {
            throw AssessmentScoringException::scopeMismatch();
        }
    }

    /**
     * @param list<AssessmentAttemptAnswer> $answers
     *
     * @return array<string, AssessmentAttemptAnswer>
     */
    private function indexAnswersByItem(array $answers): array
    {
        $indexed = [];
        foreach ($answers as $answer) {
            $indexed[$answer->getAttemptItem()->getId()->toRfc4122()] = $answer;
        }

        return $indexed;
    }

    private function findFreshDelivery(Uuid $id, LockMode $lockMode): ?AssessmentDelivery
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(AssessmentDelivery::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDelivery ? $result : null;
    }

    private function findFreshRecipient(Uuid $id, LockMode $lockMode): ?AssessmentDeliveryRecipient
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentDeliveryRecipient::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDeliveryRecipient ? $result : null;
    }

    private function findFreshPublication(Uuid $id): ?AssessmentPublication
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AssessmentPublication::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentPublication ? $result : null;
    }

    private function findFreshAssessment(Uuid $id): ?Assessment
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof Assessment ? $result : null;
    }

    private function findFreshRevision(Uuid $id): ?AssessmentRevision
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentRevision::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentRevision ? $result : null;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }

    private function mapDriverException(\Throwable $e): never
    {
        if ($e instanceof DriverException) {
            throw AssessmentScoringException::conflict($e);
        }

        throw $e;
    }
}
