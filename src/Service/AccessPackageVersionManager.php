<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessPackagePolicyHasher;
use App\Dto\SecurityAuditContext;
use App\Entity\AccessPackage;
use App\Entity\AccessPackageAssessmentGrant;
use App\Entity\AccessPackageCatalogGrant;
use App\Entity\AccessPackageLearningContentGrant;
use App\Entity\AccessPackageVersion;
use App\Entity\Assessment;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageStatus;
use App\Enum\AccessPackageVersionStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AccessEntitlementException;
use App\Repository\AccessPackageAssessmentGrantRepository;
use App\Repository\AccessPackageCatalogGrantRepository;
use App\Repository\AccessPackageLearningContentGrantRepository;
use App\Repository\AccessPackageVersionRepository;
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
 * Package version drafts, grants, and activation (supersede prior active in same TX).
 *
 * Lock order: Package → Version → Users → Grants → Audit
 * Stage 2.16 packages are platform commercial catalog: grants may only include platform published resources.
 */
final class AccessPackageVersionManager
{
    public function __construct(
        private readonly AccessPackageVersionRepository $versions,
        private readonly AccessPackageLearningContentGrantRepository $learningContentGrants,
        private readonly AccessPackageAssessmentGrantRepository $assessmentGrants,
        private readonly AccessPackageCatalogGrantRepository $catalogGrants,
        private readonly AccessPackagePolicyHasher $hasher,
        private readonly AccessPackageAuthorization $authorization,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraftVersion(
        AccessPackage $package,
        User $actor,
        ?int $validityDays,
        ?int $seatLimit,
        string $reasonCode,
    ): AccessPackageVersion {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $packageId = $package->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $packageId,
                $actorId,
                $validityDays,
                $seatLimit,
                $reasonCode,
            ): AccessPackageVersion {
                $lockedPackage = $this->findFreshPackage($packageId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedPackage instanceof AccessPackage) {
                    throw AccessEntitlementException::notFound();
                }
                if (AccessPackageStatus::Retired === $lockedPackage->getStatus()) {
                    throw AccessEntitlementException::packageRetired();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanPreparePackages($freshActor);

                $now = $this->utcNow();
                $versionNumber = $this->versions->nextVersionNumber($packageId);
                $placeholderHash = $this->computeHash(
                    $lockedPackage,
                    $versionNumber,
                    $validityDays ?? $lockedPackage->getDefaultValidityDays(),
                    $seatLimit ?? $lockedPackage->getDefaultSeatLimit(),
                    [],
                    [],
                    [],
                );

                $version = AccessPackageVersion::createDraft(
                    $lockedPackage,
                    $versionNumber,
                    $validityDays ?? $lockedPackage->getDefaultValidityDays(),
                    $seatLimit ?? $lockedPackage->getDefaultSeatLimit(),
                    $placeholderHash,
                    $freshActor,
                    $now,
                );
                $this->versions->save($version, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessPackageVersionCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_version_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $packageId->toRfc4122(),
                        'package_version_id' => $version->getId()->toRfc4122(),
                        'version_number' => $version->getVersionNumber(),
                        'policy_hash' => $version->getPolicyHash(),
                        'seat_limit' => $version->getSeatLimit(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $version;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    public function updateDraft(
        AccessPackageVersion $version,
        User $actor,
        ?int $validityDays,
        ?int $seatLimit,
        string $reasonCode,
    ): AccessPackageVersion {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $versionId = $version->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $versionId,
                $actorId,
                $validityDays,
                $seatLimit,
                $reasonCode,
            ): AccessPackageVersion {
                $locked = $this->lockDraftVersion($versionId, $actorId);
                $freshActor = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ)[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $hash = $this->recomputeHashForVersion($locked, $validityDays, $seatLimit);
                $now = $this->utcNow();
                $locked->updateDraft($validityDays, $seatLimit, $hash, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessPackageVersionUpdated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_version_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $locked->getPackage()->getId()->toRfc4122(),
                        'package_version_id' => $locked->getId()->toRfc4122(),
                        'version_number' => $locked->getVersionNumber(),
                        'policy_hash' => $locked->getPolicyHash(),
                        'seat_limit' => $locked->getSeatLimit(),
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

    public function addLearningContentGrant(
        AccessPackageVersion $version,
        LearningContent $content,
        User $actor,
        string $reasonCode,
    ): AccessPackageLearningContentGrant {
        return $this->mutateDraftGrant($version, $actor, $reasonCode, function (AccessPackageVersion $locked, \DateTimeImmutable $now) use ($content): AccessPackageLearningContentGrant {
            $freshContent = $this->findFreshLearningContent($content->getId(), LockMode::PESSIMISTIC_READ);
            if (!$freshContent instanceof LearningContent) {
                throw AccessEntitlementException::notFound();
            }
            $this->assertPlatformPublishedLearningContent($freshContent);
            $grant = AccessPackageLearningContentGrant::create($locked, $freshContent, $now);
            $this->learningContentGrants->save($grant, false);

            return $grant;
        }, 'learning_content');
    }

    public function removeLearningContentGrant(
        AccessPackageLearningContentGrant $grant,
        User $actor,
        string $reasonCode,
    ): void {
        $version = $grant->getVersion();
        $grantId = $grant->getId();
        $this->mutateDraftGrant($version, $actor, $reasonCode, function (AccessPackageVersion $locked) use ($grantId): AccessPackageLearningContentGrant {
            $fresh = $this->learningContentGrants->findOneById($grantId);
            if (!$fresh instanceof AccessPackageLearningContentGrant
                || !$fresh->getVersion()->getId()->equals($locked->getId())
            ) {
                throw AccessEntitlementException::notFound();
            }
            $this->learningContentGrants->remove($fresh, false);

            return $fresh;
        }, 'learning_content', remove: true);
    }

    public function addAssessmentGrant(
        AccessPackageVersion $version,
        Assessment $assessment,
        User $actor,
        string $reasonCode,
    ): AccessPackageAssessmentGrant {
        return $this->mutateDraftGrant($version, $actor, $reasonCode, function (AccessPackageVersion $locked, \DateTimeImmutable $now) use ($assessment): AccessPackageAssessmentGrant {
            $fresh = $this->findFreshAssessment($assessment->getId(), LockMode::PESSIMISTIC_READ);
            if (!$fresh instanceof Assessment) {
                throw AccessEntitlementException::notFound();
            }
            $this->assertPlatformPublishedAssessment($fresh);
            $grant = AccessPackageAssessmentGrant::create($locked, $fresh, $now);
            $this->assessmentGrants->save($grant, false);

            return $grant;
        }, 'assessment');
    }

    public function removeAssessmentGrant(
        AccessPackageAssessmentGrant $grant,
        User $actor,
        string $reasonCode,
    ): void {
        $version = $grant->getVersion();
        $grantId = $grant->getId();
        $this->mutateDraftGrant($version, $actor, $reasonCode, function (AccessPackageVersion $locked) use ($grantId): AccessPackageAssessmentGrant {
            $fresh = $this->assessmentGrants->findOneById($grantId);
            if (!$fresh instanceof AccessPackageAssessmentGrant
                || !$fresh->getVersion()->getId()->equals($locked->getId())
            ) {
                throw AccessEntitlementException::notFound();
            }
            $this->assessmentGrants->remove($fresh, false);

            return $fresh;
        }, 'assessment', remove: true);
    }

    public function addCatalogGrant(
        AccessPackageVersion $version,
        AccessPackageCatalogResourceKind $kind,
        ?Subject $subject,
        GradeLevel $gradeLevel,
        User $actor,
        string $reasonCode,
    ): AccessPackageCatalogGrant {
        return $this->mutateDraftGrant($version, $actor, $reasonCode, function (AccessPackageVersion $locked, \DateTimeImmutable $now) use ($kind, $subject, $gradeLevel): AccessPackageCatalogGrant {
            $freshSubject = null;
            if (AccessPackageCatalogResourceKind::LearningContent === $kind) {
                if (!$subject instanceof Subject) {
                    throw AccessEntitlementException::invalidInput('Learning content catalog grant requires subject.');
                }
                $freshSubject = $this->freshEntities->findFreshLockedSubject($subject->getId(), LockMode::PESSIMISTIC_READ);
                if (!$freshSubject instanceof Subject) {
                    throw AccessEntitlementException::notFound();
                }
            } elseif (null !== $subject) {
                throw AccessEntitlementException::invalidInput('Assessment catalog grant must not set subject.');
            }

            $grant = AccessPackageCatalogGrant::create($locked, $kind, $freshSubject, $gradeLevel, $now);
            $this->catalogGrants->save($grant, false);

            return $grant;
        }, 'catalog');
    }

    public function removeCatalogGrant(
        AccessPackageCatalogGrant $grant,
        User $actor,
        string $reasonCode,
    ): void {
        $version = $grant->getVersion();
        $grantId = $grant->getId();
        $this->mutateDraftGrant($version, $actor, $reasonCode, function (AccessPackageVersion $locked) use ($grantId): AccessPackageCatalogGrant {
            $fresh = $this->catalogGrants->findOneById($grantId);
            if (!$fresh instanceof AccessPackageCatalogGrant
                || !$fresh->getVersion()->getId()->equals($locked->getId())
            ) {
                throw AccessEntitlementException::notFound();
            }
            $this->catalogGrants->remove($fresh, false);

            return $fresh;
        }, 'catalog', remove: true);
    }

    public function activate(
        AccessPackageVersion $version,
        User $actor,
        string $reasonCode,
    ): AccessPackageVersion {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $versionId = $version->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $versionId,
                $actorId,
                $reasonCode,
            ): AccessPackageVersion {
                $locked = $this->findFreshVersion($versionId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof AccessPackageVersion) {
                    throw AccessEntitlementException::notFound();
                }
                if (AccessPackageVersionStatus::Draft !== $locked->getStatus()) {
                    throw AccessEntitlementException::invalidTransition();
                }

                $package = $this->findFreshPackage($locked->getPackage()->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$package instanceof AccessPackage) {
                    throw AccessEntitlementException::notFound();
                }
                if (AccessPackageStatus::Retired === $package->getStatus()) {
                    throw AccessEntitlementException::packageRetired();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanActivatePackages($freshActor);

                $this->assertAllGrantsStillValid($locked);
                $expectedHash = $this->recomputeHashForVersion($locked, $locked->getValidityDays(), $locked->getSeatLimit());
                if (!hash_equals($expectedHash, $locked->getPolicyHash())) {
                    throw AccessEntitlementException::hashMismatch();
                }

                $now = $this->utcNow();
                $previous = $this->versions->findActiveForPackage($package->getId());
                if ($previous instanceof AccessPackageVersion) {
                    $previousLocked = $this->findFreshVersion($previous->getId(), LockMode::PESSIMISTIC_WRITE);
                    if ($previousLocked instanceof AccessPackageVersion
                        && AccessPackageVersionStatus::Active === $previousLocked->getStatus()
                    ) {
                        $previousLocked->supersede($now);
                        $this->auditRecorder->record(new SecurityAuditContext(
                            action: SecurityAuditAction::AccessPackageVersionSuperseded,
                            actorType: SecurityAuditActorType::User,
                            outcome: SecurityAuditOutcome::Success,
                            actorUser: $freshActor,
                            metadata: [
                                'source' => 'access_package_version_manager',
                                'reason_code' => $reasonCode,
                                'package_id' => $package->getId()->toRfc4122(),
                                'package_version_id' => $previousLocked->getId()->toRfc4122(),
                                'version_number' => $previousLocked->getVersionNumber(),
                                'policy_hash' => $previousLocked->getPolicyHash(),
                            ],
                            captureRequestHashes: false,
                        ), false);
                        $this->entityManager->flush();
                    }
                }

                if (AccessPackageStatus::Draft === $package->getStatus()) {
                    $package->activate($now);
                }

                $locked->activate($freshActor, $now);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessPackageVersionActivated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_version_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $package->getId()->toRfc4122(),
                        'package_version_id' => $locked->getId()->toRfc4122(),
                        'version_number' => $locked->getVersionNumber(),
                        'policy_hash' => $locked->getPolicyHash(),
                        'seat_limit' => $locked->getSeatLimit(),
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

    /**
     * @template T
     *
     * @param callable(AccessPackageVersion, \DateTimeImmutable): T $mutator
     *
     * @return T
     */
    private function mutateDraftGrant(
        AccessPackageVersion $version,
        User $actor,
        string $reasonCode,
        callable $mutator,
        string $grantKind,
        bool $remove = false,
    ): mixed {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $versionId = $version->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $versionId,
                $actorId,
                $reasonCode,
                $mutator,
                $grantKind,
                $remove,
            ): mixed {
                $locked = $this->lockDraftVersion($versionId, $actorId);
                $freshActor = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ)[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $now = $this->utcNow();
                $result = $mutator($locked, $now);
                $this->entityManager->flush();
                $hash = $this->recomputeHashForVersion($locked, $locked->getValidityDays(), $locked->getSeatLimit());
                $locked->replacePolicyHash($hash, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $remove ? SecurityAuditAction::AccessPackageGrantRemoved : SecurityAuditAction::AccessPackageGrantAdded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_package_version_manager',
                        'reason_code' => $reasonCode,
                        'package_id' => $locked->getPackage()->getId()->toRfc4122(),
                        'package_version_id' => $locked->getId()->toRfc4122(),
                        'version_number' => $locked->getVersionNumber(),
                        'policy_hash' => $locked->getPolicyHash(),
                        'grant_kind' => $grantKind,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $result;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    private function lockDraftVersion(Uuid $versionId, Uuid $actorId): AccessPackageVersion
    {
        $locked = $this->findFreshVersion($versionId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof AccessPackageVersion) {
            throw AccessEntitlementException::notFound();
        }
        if (!$locked->getStatus()->allowsDraftMutation()) {
            throw AccessEntitlementException::invalidTransition();
        }
        $package = $this->findFreshPackage($locked->getPackage()->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$package instanceof AccessPackage) {
            throw AccessEntitlementException::notFound();
        }
        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User) {
            throw AccessEntitlementException::userNotFound();
        }
        $this->authorization->assertCanPreparePackages($freshActor);

        return $locked;
    }

    private function recomputeHashForVersion(
        AccessPackageVersion $version,
        ?int $validityDays,
        ?int $seatLimit,
    ): string {
        $package = $version->getPackage();

        return $this->computeHash(
            $package,
            $version->getVersionNumber(),
            $validityDays,
            $seatLimit,
            $this->learningContentIds($version),
            $this->assessmentIds($version),
            $this->catalogPayloads($version),
        );
    }

    /**
     * @param list<string>                                              $lcIds
     * @param list<string>                                              $assessmentIds
     * @param list<array{kind: string, subjectId?: string, grade: int}> $catalog
     */
    private function computeHash(
        AccessPackage $package,
        int $versionNumber,
        ?int $validityDays,
        ?int $seatLimit,
        array $lcIds,
        array $assessmentIds,
        array $catalog,
    ): string {
        return $this->hasher->hash(
            $package->getId(),
            $package->getCode(),
            $versionNumber,
            $package->getTargetType(),
            $validityDays,
            $seatLimit,
            $lcIds,
            $assessmentIds,
            $catalog,
        );
    }

    /** @return list<string> */
    private function learningContentIds(AccessPackageVersion $version): array
    {
        $ids = [];
        foreach ($this->learningContentGrants->findByVersion($version) as $grant) {
            $ids[] = $grant->getLearningContent()->getId()->toRfc4122();
        }

        return $ids;
    }

    /** @return list<string> */
    private function assessmentIds(AccessPackageVersion $version): array
    {
        $ids = [];
        foreach ($this->assessmentGrants->findByVersion($version) as $grant) {
            $ids[] = $grant->getAssessment()->getId()->toRfc4122();
        }

        return $ids;
    }

    /** @return list<array{kind: string, subjectId?: string, grade: int}> */
    private function catalogPayloads(AccessPackageVersion $version): array
    {
        $rows = [];
        foreach ($this->catalogGrants->findByVersion($version) as $grant) {
            $rows[] = AccessPackagePolicyHasher::catalogGrantPayload(
                $grant->getResourceKind(),
                $grant->getSubject()?->getId(),
                $grant->getGradeLevel(),
            );
        }

        return $rows;
    }

    private function assertAllGrantsStillValid(AccessPackageVersion $version): void
    {
        foreach ($this->learningContentGrants->findByVersion($version) as $grant) {
            $this->assertPlatformPublishedLearningContent($grant->getLearningContent());
        }
        foreach ($this->assessmentGrants->findByVersion($version) as $grant) {
            $this->assertPlatformPublishedAssessment($grant->getAssessment());
        }
        if (0 === \count($this->learningContentGrants->findByVersion($version))
            && 0 === \count($this->assessmentGrants->findByVersion($version))
            && 0 === \count($this->catalogGrants->findByVersion($version))
        ) {
            throw AccessEntitlementException::grantInvalid('Active package version requires at least one grant.');
        }
    }

    private function assertPlatformPublishedLearningContent(LearningContent $content): void
    {
        if (LearningContentScope::Platform !== $content->getScope()) {
            throw AccessEntitlementException::scopeMismatch('Platform packages cannot grant institution-private learning content.');
        }
        if (LearningContentStatus::Published !== $content->getStatus()) {
            throw AccessEntitlementException::resourceNotPublished();
        }
    }

    private function assertPlatformPublishedAssessment(Assessment $assessment): void
    {
        if (AssessmentScope::Platform !== $assessment->getScope()) {
            throw AccessEntitlementException::scopeMismatch('Platform packages cannot grant institution-private assessments.');
        }
        if (AssessmentStatus::Published !== $assessment->getStatus()) {
            throw AccessEntitlementException::resourceNotPublished();
        }
    }

    private function findFreshPackage(Uuid $id, LockMode $lockMode): ?AccessPackage
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AccessPackage::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode)
            ->getOneOrNullResult();

        return $entity instanceof AccessPackage ? $entity : null;
    }

    private function findFreshVersion(Uuid $id, LockMode $lockMode): ?AccessPackageVersion
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(AccessPackageVersion::class, 'v')
            ->where('v.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode)
            ->getOneOrNullResult();

        return $entity instanceof AccessPackageVersion ? $entity : null;
    }

    private function findFreshLearningContent(Uuid $id, LockMode $lockMode): ?LearningContent
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(LearningContent::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode)
            ->getOneOrNullResult();

        return $entity instanceof LearningContent ? $entity : null;
    }

    private function findFreshAssessment(Uuid $id, LockMode $lockMode): ?Assessment
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode)
            ->getOneOrNullResult();

        return $entity instanceof Assessment ? $entity : null;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
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
