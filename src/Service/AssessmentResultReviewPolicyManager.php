<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentResultReviewPolicy;
use App\Entity\User;
use App\Enum\ResultReviewAvailabilityMode;
use App\Enum\ResultReviewPolicyStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AssessmentResultReviewException;
use App\Repository\AssessmentResultReviewPolicyRepository;
use App\ResultReview\AssessmentResultReviewPolicyHasher;
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
 * Versioned review-policy lifecycle for AssessmentDelivery.
 *
 * Active uniqueness is enforced by DB triggers on assessment_result_review_policies.
 *
 * Lock order: Institution → Delivery WRITE → Users → Policy → audit.
 */
final class AssessmentResultReviewPolicyManager
{
    public function __construct(
        private readonly AssessmentResultReviewPolicyRepository $policies,
        private readonly AssessmentResultReviewPolicyHasher $hasher,
        private readonly AssessmentResultReviewAccessGate $accessGate,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraft(
        AssessmentDelivery $delivery,
        User $actor,
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
        string $reasonCode,
    ): AssessmentResultReviewPolicy {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $deliveryId = $delivery->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $deliveryId,
                $actorId,
                $availabilityMode,
                $scheduledAt,
                $showScoreSummary,
                $showItemOutcomes,
                $showStudentAnswer,
                $showCorrectAnswer,
                $showExplanation,
                $reasonCode,
            ): AssessmentResultReviewPolicy {
                [$freshDelivery, $freshActor] = $this->lockDeliveryAndActor($deliveryId, $actorId);
                $this->accessGate->assertCanManagePolicy($freshActor, $freshDelivery);

                $now = $this->utcNow();
                $scheduled = null === $scheduledAt ? null : UtcInstant::ensure($scheduledAt);
                $hash = $this->computeHash(
                    $availabilityMode,
                    $scheduled,
                    $showScoreSummary,
                    $showItemOutcomes,
                    $showStudentAnswer,
                    $showCorrectAnswer,
                    $showExplanation,
                );
                $version = $this->policies->findMaxVersion($freshDelivery->getId()) + 1;
                $policy = AssessmentResultReviewPolicy::createDraft(
                    $freshDelivery,
                    $version,
                    $freshActor,
                    $availabilityMode,
                    $scheduled,
                    $showScoreSummary,
                    $showItemOutcomes,
                    $showStudentAnswer,
                    $showCorrectAnswer,
                    $showExplanation,
                    $reasonCode,
                    $hash,
                    $now,
                );
                $this->policies->save($policy, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentResultReviewPolicyCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: $this->auditMetadata($policy, $reasonCode, 'assessment_result_review_policy_manager'),
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $policy;
            });
        } catch (AssessmentResultReviewException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentResultReviewException::conflict();
        } catch (\Throwable $e) {
            if ($e instanceof DriverException) {
                throw AssessmentResultReviewException::conflict();
            }
            throw $e;
        }
    }

    public function updateDraft(
        AssessmentResultReviewPolicy $policy,
        User $actor,
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
        string $reasonCode,
    ): AssessmentResultReviewPolicy {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $policyId = $policy->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $policyId,
                $actorId,
                $availabilityMode,
                $scheduledAt,
                $showScoreSummary,
                $showItemOutcomes,
                $showStudentAnswer,
                $showCorrectAnswer,
                $showExplanation,
                $reasonCode,
            ): AssessmentResultReviewPolicy {
                $lockedPolicy = $this->findFreshPolicy($policyId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedPolicy instanceof AssessmentResultReviewPolicy) {
                    throw AssessmentResultReviewException::notFound();
                }
                if (ResultReviewPolicyStatus::Draft !== $lockedPolicy->getStatus()) {
                    throw AssessmentResultReviewException::invalidTransition();
                }

                [$freshDelivery, $freshActor] = $this->lockDeliveryAndActor(
                    $lockedPolicy->getDelivery()->getId(),
                    $actorId,
                );
                $this->accessGate->assertCanManagePolicy($freshActor, $freshDelivery);

                $now = $this->utcNow();
                $scheduled = null === $scheduledAt ? null : UtcInstant::ensure($scheduledAt);
                $hash = $this->computeHash(
                    $availabilityMode,
                    $scheduled,
                    $showScoreSummary,
                    $showItemOutcomes,
                    $showStudentAnswer,
                    $showCorrectAnswer,
                    $showExplanation,
                );
                $lockedPolicy->updateDraft(
                    $availabilityMode,
                    $scheduled,
                    $showScoreSummary,
                    $showItemOutcomes,
                    $showStudentAnswer,
                    $showCorrectAnswer,
                    $showExplanation,
                    $reasonCode,
                    $hash,
                    $now,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentResultReviewPolicyUpdated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: $this->auditMetadata($lockedPolicy, $reasonCode, 'assessment_result_review_policy_manager'),
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $lockedPolicy;
            });
        } catch (AssessmentResultReviewException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentResultReviewException::conflict();
        } catch (\Throwable $e) {
            if ($e instanceof DriverException) {
                throw AssessmentResultReviewException::conflict();
            }
            throw $e;
        }
    }

    public function activate(
        AssessmentResultReviewPolicy $policy,
        User $actor,
        string $reasonCode,
    ): AssessmentResultReviewPolicy {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $policyId = $policy->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $policyId,
                $actorId,
                $reasonCode,
            ): AssessmentResultReviewPolicy {
                $lockedPolicy = $this->findFreshPolicy($policyId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedPolicy instanceof AssessmentResultReviewPolicy) {
                    throw AssessmentResultReviewException::notFound();
                }
                if (ResultReviewPolicyStatus::Draft !== $lockedPolicy->getStatus()) {
                    throw AssessmentResultReviewException::invalidTransition();
                }

                [$freshDelivery, $freshActor] = $this->lockDeliveryAndActor(
                    $lockedPolicy->getDelivery()->getId(),
                    $actorId,
                );
                $this->accessGate->assertCanManagePolicy($freshActor, $freshDelivery);

                $now = $this->utcNow();
                $previous = $this->policies->findActiveForDelivery($freshDelivery->getId());
                if ($previous instanceof AssessmentResultReviewPolicy) {
                    $previousLocked = $this->findFreshPolicy($previous->getId(), LockMode::PESSIMISTIC_WRITE);
                    if ($previousLocked instanceof AssessmentResultReviewPolicy
                        && ResultReviewPolicyStatus::Active === $previousLocked->getStatus()
                    ) {
                        $previousLocked->supersede($now);
                        $this->auditRecorder->record(new SecurityAuditContext(
                            action: SecurityAuditAction::AssessmentResultReviewPolicySuperseded,
                            actorType: SecurityAuditActorType::User,
                            outcome: SecurityAuditOutcome::Success,
                            actorUser: $freshActor,
                            metadata: $this->auditMetadata(
                                $previousLocked,
                                $reasonCode,
                                'assessment_result_review_policy_manager',
                            ),
                            captureRequestHashes: false,
                        ), false);
                        // Flush supersede before activating the new policy so active
                        // guard AU/AI triggers do not collide in one UnitOfWork flush.
                        $this->entityManager->flush();
                    }
                }

                $lockedPolicy->activate($freshActor, $reasonCode, $now);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentResultReviewPolicyActivated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: $this->auditMetadata($lockedPolicy, $reasonCode, 'assessment_result_review_policy_manager'),
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $lockedPolicy;
            });
        } catch (AssessmentResultReviewException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentResultReviewException::conflict();
        } catch (\Throwable $e) {
            if ($e instanceof DriverException) {
                throw AssessmentResultReviewException::conflict();
            }
            throw $e;
        }
    }

    /**
     * Clone an existing policy as a new draft version (does not activate).
     *
     * Pass overrides to replace cloned flag/mode values. When availabilityMode is
     * overridden away from scheduled_after_close, scheduledAt is forced null.
     */
    public function createNewVersion(
        AssessmentResultReviewPolicy $source,
        User $actor,
        string $reasonCode,
        ?ResultReviewAvailabilityMode $availabilityMode = null,
        ?\DateTimeImmutable $scheduledAt = null,
        bool $hasScheduledAtOverride = false,
        ?bool $showScoreSummary = null,
        ?bool $showItemOutcomes = null,
        ?bool $showStudentAnswer = null,
        ?bool $showCorrectAnswer = null,
        ?bool $showExplanation = null,
    ): AssessmentResultReviewPolicy {
        $mode = $availabilityMode ?? $source->getAvailabilityMode();
        if ($hasScheduledAtOverride || null !== $availabilityMode) {
            $scheduled = ResultReviewAvailabilityMode::ScheduledAfterClose === $mode
                ? $scheduledAt
                : null;
        } else {
            $scheduled = $source->getScheduledAt();
        }

        return $this->createDraft(
            $source->getDelivery(),
            $actor,
            $mode,
            $scheduled,
            $showScoreSummary ?? $source->showScoreSummary(),
            $showItemOutcomes ?? $source->showItemOutcomes(),
            $showStudentAnswer ?? $source->showStudentAnswer(),
            $showCorrectAnswer ?? $source->showCorrectAnswer(),
            $showExplanation ?? $source->showExplanation(),
            $reasonCode,
        );
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $trimmed = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $trimmed)) {
            throw AssessmentResultReviewException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        return $trimmed;
    }

    /**
     * @return array{0: AssessmentDelivery, 1: User}
     */
    private function lockDeliveryAndActor(Uuid $deliveryId, Uuid $actorId): array
    {
        $institutionId = $this->entityManager->getConnection()->fetchOne(
            'SELECT institution_id FROM assessment_deliveries WHERE id = :id LIMIT 1',
            ['id' => $deliveryId->toBinary()],
        );
        if (false === $institutionId) {
            throw AssessmentResultReviewException::notFound();
        }
        $institutionUuid = Uuid::fromBinary((string) $institutionId);
        $institution = $this->freshEntities->findFreshLockedInstitution(
            $institutionUuid,
            LockMode::PESSIMISTIC_READ,
        );
        if (null === $institution) {
            throw AssessmentResultReviewException::notFound();
        }

        $freshDelivery = $this->findFreshDelivery($deliveryId, LockMode::PESSIMISTIC_WRITE);
        if (!$freshDelivery instanceof AssessmentDelivery) {
            throw AssessmentResultReviewException::notFound();
        }

        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User
            || !$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
        ) {
            throw AssessmentResultReviewException::unauthorized();
        }

        return [$freshDelivery, $freshActor];
    }

    private function computeHash(
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
    ): string {
        return $this->hasher->hash(
            AssessmentResultReviewPolicyHasher::SCHEMA_VERSION,
            $availabilityMode,
            $scheduledAt,
            $showScoreSummary,
            $showItemOutcomes,
            $showStudentAnswer,
            $showCorrectAnswer,
            $showExplanation,
        );
    }

    /**
     * @return array<string, scalar>
     */
    private function auditMetadata(
        AssessmentResultReviewPolicy $policy,
        string $reasonCode,
        string $source,
    ): array {
        return [
            'source' => $source,
            'reason_code' => $reasonCode,
            'delivery_id' => $policy->getDelivery()->getId()->toRfc4122(),
            'institution_id' => $policy->getInstitution()->getId()->toRfc4122(),
            'policy_id' => $policy->getId()->toRfc4122(),
            'policy_version' => $policy->getVersion(),
            'availability_mode' => $policy->getAvailabilityMode()->value,
            'status' => $policy->getStatus()->value,
        ];
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

    private function findFreshPolicy(Uuid $id, LockMode $lockMode): ?AssessmentResultReviewPolicy
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AssessmentResultReviewPolicy::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentResultReviewPolicy ? $result : null;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
