<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\StoredMediaAsset;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Enum\UserRole;
use App\Exception\LearningContentException;
use App\LearningContent\Media\StorageKeyFactory;
use App\LearningContent\Media\StoredMediaAssetPolicy;
use App\Repository\StoredMediaAssetRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Metadata-only stored media lifecycle (no real upload/SDK in Stage 2.15).
 *
 * Lock order: Institution → Users UUID order → StoredMediaAsset → Audit
 */
final class StoredMediaAssetManager
{
    public function __construct(
        private readonly StoredMediaAssetRepository $assets,
        private readonly StoredMediaAssetPolicy $policy,
        private readonly StorageKeyFactory $storageKeyFactory,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function registerMetadata(
        User $actor,
        StoredMediaAssetScope $scope,
        ?Institution $institution,
        StoredMediaAssetKind $kind,
        StoredMediaStorageProvider $storageProvider,
        string $originalFilename,
        string $mimeType,
        int $byteSize,
        string $contentSha256,
        string $reasonCode,
    ): StoredMediaAsset {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $this->policy->assertValidRegistration($kind, $mimeType, $originalFilename, $byteSize, $contentSha256);
        $actorId = $actor->getId();
        $institutionId = $institution?->getId();
        $assetId = new UuidV7();
        $storageKey = $this->storageKeyFactory->create(
            $scope,
            $institutionId,
            $kind,
            $assetId,
            $contentSha256,
        );

        try {
            $asset = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $scope,
                $institutionId,
                $kind,
                $storageProvider,
                $storageKey,
                $originalFilename,
                $mimeType,
                $byteSize,
                $contentSha256,
                $assetId,
                $reasonCode,
            ): StoredMediaAsset {
                $lockedInstitution = null;
                if (StoredMediaAssetScope::Institution === $scope) {
                    if (null === $institutionId) {
                        throw LearningContentException::scopeMismatch();
                    }
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw LearningContentException::notFound();
                    }
                } elseif (null !== $institutionId) {
                    throw LearningContentException::scopeMismatch();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayManageMedia($freshActor, $scope, $lockedInstitution);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $asset = StoredMediaAsset::register(
                    $scope,
                    $lockedInstitution,
                    $kind,
                    $storageProvider,
                    $storageKey,
                    $originalFilename,
                    strtolower(trim($mimeType)),
                    $byteSize,
                    $contentSha256,
                    $freshActor,
                    $now,
                    $assetId,
                );
                $this->assets->save($asset, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::StoredMediaAssetRegistered,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'stored_media_asset_manager',
                        'reason_code' => $reasonCode,
                        'asset_id' => $asset->getId()->toRfc4122(),
                        'asset_kind' => $kind->value,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'status' => $asset->getStatus()->value,
                        'scan_status' => $asset->getScanStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $asset;
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        }

        return $asset;
    }

    public function markScanStatus(
        StoredMediaAsset $asset,
        User $actor,
        StoredMediaScanStatus $scanStatus,
        string $reasonCode,
    ): void {
        $this->mutateAsset(
            $asset,
            $actor,
            $reasonCode,
            SecurityAuditAction::StoredMediaAssetScanUpdated,
            static function (StoredMediaAsset $a, \DateTimeImmutable $now) use ($scanStatus): void {
                $a->markScanStatus($scanStatus, $now);
            },
        );
    }

    public function markReady(StoredMediaAsset $asset, User $actor, string $reasonCode): void
    {
        $this->mutateAsset(
            $asset,
            $actor,
            $reasonCode,
            SecurityAuditAction::StoredMediaAssetMarkedReady,
            static function (StoredMediaAsset $a, \DateTimeImmutable $now): void {
                $a->markReady($now);
            },
        );
    }

    public function quarantine(StoredMediaAsset $asset, User $actor, string $reasonCode): void
    {
        $this->mutateAsset(
            $asset,
            $actor,
            $reasonCode,
            SecurityAuditAction::StoredMediaAssetQuarantined,
            static function (StoredMediaAsset $a, \DateTimeImmutable $now): void {
                $a->quarantine($now);
            },
        );
    }

    public function archive(StoredMediaAsset $asset, User $actor, string $reasonCode): void
    {
        $this->mutateAsset(
            $asset,
            $actor,
            $reasonCode,
            SecurityAuditAction::StoredMediaAssetArchived,
            static function (StoredMediaAsset $a, \DateTimeImmutable $now): void {
                $a->archive($now);
            },
        );
    }

    /**
     * @param callable(StoredMediaAsset, \DateTimeImmutable): void $mutator
     */
    private function mutateAsset(
        StoredMediaAsset $asset,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $assetId = $asset->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $assetId,
                $actorId,
                $reasonCode,
                $action,
                $mutator,
            ): void {
                $lockedAsset = $this->freshEntities->findFreshLockedStoredMediaAsset(
                    $assetId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedAsset instanceof StoredMediaAsset) {
                    throw LearningContentException::notFound();
                }

                $lockedInstitution = null;
                if (StoredMediaAssetScope::Institution === $lockedAsset->getScope()) {
                    $institution = $lockedAsset->getInstitution();
                    if (!$institution instanceof Institution) {
                        throw LearningContentException::scopeMismatch();
                    }
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institution->getId(),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayManageMedia($freshActor, $lockedAsset->getScope(), $lockedInstitution);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $mutator($lockedAsset, $now);
                $this->assets->save($lockedAsset, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'stored_media_asset_manager',
                        'reason_code' => $reasonCode,
                        'asset_id' => $lockedAsset->getId()->toRfc4122(),
                        'asset_kind' => $lockedAsset->getKind()->value,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'status' => $lockedAsset->getStatus()->value,
                        'scan_status' => $lockedAsset->getScanStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        }
    }

    private function assertActorMayManageMedia(
        User $actor,
        StoredMediaAssetScope $scope,
        ?Institution $institution,
    ): void {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw LearningContentException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if (StoredMediaAssetScope::Platform === $scope) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher, UserRole::Teacher])) {
                return;
            }
            throw LearningContentException::unauthorized();
        }
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw LearningContentException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw LearningContentException::unauthorized();
        }
        if (\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
            InstitutionMembershipRole::Teacher,
        ], true)) {
            return;
        }
        throw LearningContentException::unauthorized();
    }

    /**
     * @param list<UserRole> $roles
     */
    private function hasAnyRole(User $actor, array $roles): bool
    {
        $actorRoles = $actor->getRoles();
        foreach ($roles as $role) {
            if (\in_array($role->value, $actorRoles, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = strtolower(trim($reasonCode));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw LearningContentException::invalidInput('reason_code format is invalid.');
        }

        return $reasonCode;
    }
}
