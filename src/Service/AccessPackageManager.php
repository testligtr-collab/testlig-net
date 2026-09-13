<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AccessPackage;
use App\Entity\Assessment;
use App\Entity\AssessmentAccessPolicy;
use App\Entity\LearningContent;
use App\Entity\LearningContentAccessPolicy;
use App\Entity\User;
use App\Enum\AccessPackageStatus;
use App\Enum\AccessPackageTargetType;
use App\Enum\ResourceAccessClass;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AccessEntitlementException;
use App\Repository\AccessPackageRepository;
use App\Repository\AssessmentAccessPolicyRepository;
use App\Repository\LearningContentAccessPolicyRepository;
use App\Security\AccessPackageAuthorization;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Access package lifecycle (draft → active → retired).
 *
 * Lock order: AccessPackage → Users UUID order → AccessPolicy → Audit
 */
final class AccessPackageManager
{
    public function __construct(
        private readonly AccessPackageRepository $packages,
        private readonly LearningContentAccessPolicyRepository $learningContentPolicies,
        private readonly AssessmentAccessPolicyRepository $assessmentPolicies,
        private readonly AccessPackageAuthorization $authorization,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        User $actor,
        string $code,
        string $name,
        ?string $description,
        AccessPackageTargetType $targetType,
        ?int $defaultValidityDays,
        ?int $defaultSeatLimit,
        string $reasonCode,
    ): AccessPackage {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $code = $this->normalizeCode($code);
        $names = $this->normalizeName($name);
        $description = $this->normalizeDescription($description);
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $code,
                $names,
                $description,
                $targetType,
                $defaultValidityDays,
                $defaultSeatLimit,
                $reasonCode,
            ): AccessPackage {
                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanPreparePackages($freshActor);

                $now = $this->utcNow();
                $package = AccessPackage::createDraft(
                    $code,
                    $names['name'],
                    $names['normalizedName'],
                    $description,
                    $targetType,
                    $defaultValidityDays,
                    $defaultSeatLimit,
                    $freshActor,
                    $now,
                );
                $this->packages->save($package, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessPackageCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $package->getId()->toRfc4122(),
                        'code' => $package->getCode(),
                        'target_type' => $package->getTargetType()->value,
                        'status' => $package->getStatus()->value,
                        'seat_limit' => $package->getDefaultSeatLimit(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $package;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    public function updateDraftFields(
        AccessPackage $package,
        User $actor,
        string $name,
        ?string $description,
        ?int $defaultValidityDays,
        ?int $defaultSeatLimit,
        string $reasonCode,
    ): AccessPackage {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $names = $this->normalizeName($name);
        $description = $this->normalizeDescription($description);
        $packageId = $package->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $packageId,
                $actorId,
                $names,
                $description,
                $defaultValidityDays,
                $defaultSeatLimit,
                $reasonCode,
            ): AccessPackage {
                $locked = $this->findFreshPackage($packageId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof AccessPackage) {
                    throw AccessEntitlementException::notFound();
                }
                if (AccessPackageStatus::Retired === $locked->getStatus()) {
                    throw AccessEntitlementException::invalidTransition();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                if (AccessPackageStatus::Active === $locked->getStatus()) {
                    $this->authorization->assertCanActivatePackages($freshActor);
                } else {
                    $this->authorization->assertCanPreparePackages($freshActor);
                }

                $now = $this->utcNow();
                $locked->updateDraftFields(
                    $names['name'],
                    $names['normalizedName'],
                    $description,
                    $defaultValidityDays,
                    $defaultSeatLimit,
                    $now,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessPackageUpdated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $locked->getId()->toRfc4122(),
                        'code' => $locked->getCode(),
                        'status' => $locked->getStatus()->value,
                        'seat_limit' => $locked->getDefaultSeatLimit(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $locked;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    public function activate(AccessPackage $package, User $actor, string $reasonCode): AccessPackage
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $packageId = $package->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $packageId,
                $actorId,
                $reasonCode,
            ): AccessPackage {
                $locked = $this->findFreshPackage($packageId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof AccessPackage) {
                    throw AccessEntitlementException::notFound();
                }
                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanActivatePackages($freshActor);

                $now = $this->utcNow();
                $locked->activate($now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessPackageActivated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $locked->getId()->toRfc4122(),
                        'code' => $locked->getCode(),
                        'status' => $locked->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $locked;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    public function retire(AccessPackage $package, User $actor, string $reasonCode): AccessPackage
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $packageId = $package->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $packageId,
                $actorId,
                $reasonCode,
            ): AccessPackage {
                $locked = $this->findFreshPackage($packageId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof AccessPackage) {
                    throw AccessEntitlementException::notFound();
                }
                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanActivatePackages($freshActor);

                $now = $this->utcNow();
                $locked->retire($now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessPackageRetired,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $locked->getId()->toRfc4122(),
                        'code' => $locked->getCode(),
                        'status' => $locked->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $locked;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    public function setLearningContentAccessPolicy(
        LearningContent $content,
        User $actor,
        ResourceAccessClass $accessClass,
        string $reasonCode,
    ): LearningContentAccessPolicy {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $contentId = $content->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $contentId,
                $actorId,
                $accessClass,
                $reasonCode,
            ): LearningContentAccessPolicy {
                $lockedContent = $this->findFreshLearningContent($contentId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedContent instanceof LearningContent) {
                    throw AccessEntitlementException::notFound();
                }
                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanSetResourceAccessPolicy($freshActor);

                $now = $this->utcNow();
                $policy = $this->learningContentPolicies->findForContent($contentId);
                if ($policy instanceof LearningContentAccessPolicy) {
                    $policy->update($accessClass, $freshActor, $now);
                } else {
                    $policy = LearningContentAccessPolicy::create(
                        $lockedContent,
                        $accessClass,
                        $freshActor,
                        $now,
                    );
                    $this->learningContentPolicies->save($policy, false);
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentAccessPolicySet,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $contentId->toRfc4122(),
                        'access_class' => $accessClass->value,
                        'resource_type' => 'learning_content',
                        'resource_id' => $contentId->toRfc4122(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $policy;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    public function setAssessmentAccessPolicy(
        Assessment $assessment,
        User $actor,
        ResourceAccessClass $accessClass,
        string $reasonCode,
    ): AssessmentAccessPolicy {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $assessmentId = $assessment->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $assessmentId,
                $actorId,
                $accessClass,
                $reasonCode,
            ): AssessmentAccessPolicy {
                $lockedAssessment = $this->findFreshAssessment($assessmentId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedAssessment instanceof Assessment) {
                    throw AccessEntitlementException::notFound();
                }
                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanSetResourceAccessPolicy($freshActor);

                $now = $this->utcNow();
                $policy = $this->assessmentPolicies->findForAssessment($assessmentId);
                if ($policy instanceof AssessmentAccessPolicy) {
                    $policy->update($accessClass, $freshActor, $now);
                } else {
                    $policy = AssessmentAccessPolicy::create(
                        $lockedAssessment,
                        $accessClass,
                        $freshActor,
                        $now,
                    );
                    $this->assessmentPolicies->save($policy, false);
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentAccessPolicySet,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_manager',
                        'reason_code' => $reasonCode,
                        'assessment_id' => $assessmentId->toRfc4122(),
                        'access_class' => $accessClass->value,
                        'resource_type' => 'assessment',
                        'resource_id' => $assessmentId->toRfc4122(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $policy;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    private function findFreshPackage(Uuid $id, LockMode $lockMode): ?AccessPackage
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AccessPackage::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode);
        $entity = $query->getOneOrNullResult();

        return $entity instanceof AccessPackage ? $entity : null;
    }

    private function findFreshLearningContent(Uuid $id, LockMode $lockMode): ?LearningContent
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(LearningContent::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode);
        $entity = $query->getOneOrNullResult();

        return $entity instanceof LearningContent ? $entity : null;
    }

    private function findFreshAssessment(Uuid $id, LockMode $lockMode): ?Assessment
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode);
        $entity = $query->getOneOrNullResult();

        return $entity instanceof Assessment ? $entity : null;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            throw AccessEntitlementException::invalidInput('Package code must match ^[a-z][a-z0-9_]{1,63}$.');
        }

        return $code;
    }

    /**
     * @return array{name: string, normalizedName: string}
     */
    private function normalizeName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ('' === $name || mb_strlen($name) > 200) {
            throw AccessEntitlementException::invalidInput('Package name must be 1-200 characters.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $name)) {
            throw AccessEntitlementException::invalidInput('Package name must not contain control characters.');
        }

        return [
            'name' => $name,
            'normalizedName' => mb_strtolower($name, 'UTF-8'),
        ];
    }

    private function normalizeDescription(?string $description): ?string
    {
        if (null === $description) {
            return null;
        }
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        if ('' === $description) {
            return null;
        }
        if (mb_strlen($description) > 4000) {
            throw AccessEntitlementException::invalidInput('Description must be at most 4000 characters.');
        }

        return $description;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = strtolower(trim($reasonCode));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw AccessEntitlementException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
