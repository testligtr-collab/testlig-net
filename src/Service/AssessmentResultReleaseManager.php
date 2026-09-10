<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AssessmentResultRelease;
use App\Entity\AssessmentScoringRun;
use App\Entity\User;
use App\Enum\ResultReleaseStatus;
use App\Enum\ScoringRunStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentResultReleaseRepository;
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
 * Versioned result release / withdraw for completed scoring runs.
 *
 * Active release uniqueness is enforced by DB triggers on assessment_result_releases.
 *
 * Lock order: ScoringRun READ → previous active Release WRITE → new Release → audit.
 */
final class AssessmentResultReleaseManager
{
    public function __construct(
        private readonly AssessmentResultReleaseRepository $releases,
        private readonly ScoringContentPolicy $contentPolicy,
        private readonly AssessmentResultAccessGate $accessGate,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function release(
        AssessmentScoringRun $scoringRun,
        User $actor,
        string $reasonCode,
    ): AssessmentResultRelease {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $runId = $scoringRun->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $runId,
                $actorId,
                $reasonCode,
            ): AssessmentResultRelease {
                $lockedRun = $this->findFreshRun($runId, LockMode::PESSIMISTIC_READ);
                if (!$lockedRun instanceof AssessmentScoringRun) {
                    throw AssessmentScoringException::notFound();
                }
                if (ScoringRunStatus::Completed !== $lockedRun->getStatus()) {
                    throw AssessmentScoringException::releaseNotAllowed();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User
                    || !$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
                ) {
                    throw AssessmentScoringException::unauthorized();
                }
                $this->accessGate->assertCanManageRelease($freshActor, $lockedRun->getAttempt());

                $now = $this->utcNow();
                $previous = $this->releases->findActiveReleasedForAttempt($lockedRun->getAttempt()->getId());
                if ($previous instanceof AssessmentResultRelease) {
                    $previousLocked = $this->findFreshRelease($previous->getId(), LockMode::PESSIMISTIC_WRITE);
                    if ($previousLocked instanceof AssessmentResultRelease
                        && ResultReleaseStatus::Released === $previousLocked->getStatus()
                    ) {
                        $previousLocked->supersede($now);
                        $this->auditRecorder->record(new SecurityAuditContext(
                            action: SecurityAuditAction::AssessmentResultSuperseded,
                            actorType: SecurityAuditActorType::User,
                            outcome: SecurityAuditOutcome::Success,
                            actorUser: $freshActor,
                            subjectUser: $lockedRun->getUser(),
                            metadata: [
                                'source' => 'assessment_result_release_manager',
                                'reason_code' => $reasonCode,
                                'attempt_id' => $lockedRun->getAttempt()->getId()->toRfc4122(),
                                'result_release_id' => $previousLocked->getId()->toRfc4122(),
                                'release_number' => $previousLocked->getReleaseNumber(),
                                'scoring_run_id' => $previousLocked->getScoringRun()->getId()->toRfc4122(),
                                'status' => ResultReleaseStatus::Superseded->value,
                            ],
                            captureRequestHashes: false,
                        ), false);
                        // Flush supersede before inserting the new released row so the active
                        // guard AU/AI triggers do not collide in one UnitOfWork flush.
                        $this->entityManager->flush();
                    }
                }

                $releaseNumber = $this->releases->findMaxReleaseNumber($lockedRun->getAttempt()->getId()) + 1;
                $release = AssessmentResultRelease::createReleased(
                    $lockedRun,
                    $releaseNumber,
                    $freshActor,
                    $reasonCode,
                    $now,
                );
                $this->releases->save($release, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentResultReleased,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $lockedRun->getUser(),
                    metadata: [
                        'source' => 'assessment_result_release_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedRun->getAttempt()->getId()->toRfc4122(),
                        'result_release_id' => $release->getId()->toRfc4122(),
                        'release_number' => $release->getReleaseNumber(),
                        'scoring_run_id' => $lockedRun->getId()->toRfc4122(),
                        'run_number' => $lockedRun->getRunNumber(),
                        'status' => $release->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $release;
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

    public function withdraw(
        AssessmentResultRelease $release,
        User $actor,
        string $reasonCode,
    ): void {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $releaseId = $release->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $releaseId,
                $actorId,
                $reasonCode,
            ): void {
                $lockedRelease = $this->findFreshRelease($releaseId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedRelease instanceof AssessmentResultRelease) {
                    throw AssessmentScoringException::notFound();
                }
                if (ResultReleaseStatus::Released !== $lockedRelease->getStatus()) {
                    throw AssessmentScoringException::invalidTransition();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User
                    || !$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
                ) {
                    throw AssessmentScoringException::unauthorized();
                }
                $this->accessGate->assertCanManageRelease($freshActor, $lockedRelease->getAttempt());

                $now = $this->utcNow();
                $lockedRelease->withdraw($freshActor, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentResultWithdrawn,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $lockedRelease->getAttempt()->getUser(),
                    metadata: [
                        'source' => 'assessment_result_release_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedRelease->getAttempt()->getId()->toRfc4122(),
                        'result_release_id' => $lockedRelease->getId()->toRfc4122(),
                        'release_number' => $lockedRelease->getReleaseNumber(),
                        'scoring_run_id' => $lockedRelease->getScoringRun()->getId()->toRfc4122(),
                        'status' => ResultReleaseStatus::Withdrawn->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
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

    private function findFreshRelease(Uuid $id, LockMode $lockMode): ?AssessmentResultRelease
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentResultRelease::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentResultRelease ? $result : null;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
