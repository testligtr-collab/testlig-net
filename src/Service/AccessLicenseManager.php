<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AccessLicense;
use App\Entity\AccessPackage;
use App\Entity\AccessPackageVersion;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AccessLicenseLicenseeType;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessLicenseStatus;
use App\Enum\AccessPackageTargetType;
use App\Enum\AccessPackageVersionStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AccessEntitlementException;
use App\Repository\AccessLicenseRepository;
use App\Security\AccessPackageAuthorization;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * User / institution license lifecycle with lazy expiry evaluation.
 *
 * Lock order: Institution (if any) → Package → Version → License → Users → Audit
 * Validity window: validFrom <= now < validUntil (exclusive upper bound).
 */
final class AccessLicenseManager
{
    public function __construct(
        private readonly AccessLicenseRepository $licenses,
        private readonly AccessPackageAuthorization $authorization,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createUserLicense(
        AccessPackageVersion $version,
        User $licensee,
        User $actor,
        AccessLicenseSourceType $sourceType,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil,
        string $reasonCode,
        ?string $externalReference = null,
    ): AccessLicense {
        return $this->createLicense(
            $version,
            AccessLicenseLicenseeType::User,
            $licensee,
            null,
            $actor,
            $sourceType,
            $validFrom,
            $validUntil,
            null,
            $reasonCode,
            $externalReference,
        );
    }

    public function createInstitutionLicense(
        AccessPackageVersion $version,
        Institution $institution,
        User $actor,
        AccessLicenseSourceType $sourceType,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil,
        ?int $seatLimit,
        string $reasonCode,
        ?string $externalReference = null,
    ): AccessLicense {
        return $this->createLicense(
            $version,
            AccessLicenseLicenseeType::Institution,
            null,
            $institution,
            $actor,
            $sourceType,
            $validFrom,
            $validUntil,
            $seatLimit,
            $reasonCode,
            $externalReference,
        );
    }

    public function activate(AccessLicense $license, User $actor, string $reasonCode): AccessLicense
    {
        return $this->mutate($license, $actor, $reasonCode, SecurityAuditAction::AccessLicenseActivated, static function (AccessLicense $l, User $a, \DateTimeImmutable $now): void {
            $l->activate($a, $now);
        });
    }

    public function suspend(AccessLicense $license, User $actor, string $reasonCode): AccessLicense
    {
        return $this->mutate($license, $actor, $reasonCode, SecurityAuditAction::AccessLicenseSuspended, static function (AccessLicense $l, User $a, \DateTimeImmutable $now): void {
            $l->suspend($now);
        });
    }

    public function reactivate(AccessLicense $license, User $actor, string $reasonCode): AccessLicense
    {
        return $this->mutate($license, $actor, $reasonCode, SecurityAuditAction::AccessLicenseReactivated, static function (AccessLicense $l, User $a, \DateTimeImmutable $now): void {
            $l->reactivate($now);
        });
    }

    public function revoke(AccessLicense $license, User $actor, string $reasonCode): AccessLicense
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        return $this->mutate($license, $actor, $reasonCode, SecurityAuditAction::AccessLicenseRevoked, static function (AccessLicense $l, User $a, \DateTimeImmutable $now) use ($reasonCode): void {
            $l->revoke($a, $reasonCode, $now);
        });
    }

    /**
     * Lazy expiry: if active/suspended and now >= validUntil, mark expired in same TX.
     */
    public function evaluateAndExpireIfNeeded(AccessLicense $license): AccessLicense
    {
        $licenseId = $license->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($licenseId): AccessLicense {
                $locked = $this->findFreshLicense($licenseId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof AccessLicense) {
                    throw AccessEntitlementException::notFound();
                }
                $now = UtcInstant::ensure($this->utcNow());
                if ((AccessLicenseStatus::Active === $locked->getStatus() || AccessLicenseStatus::Suspended === $locked->getStatus())
                    && $now >= $locked->getValidUntil()
                ) {
                    $locked->markExpired($now);
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::AccessLicenseExpired,
                        actorType: SecurityAuditActorType::System,
                        outcome: SecurityAuditOutcome::Success,
                        metadata: [
                            'source' => 'access_license_manager',
                            'reason_code' => 'lazy_expire',
                            'license_id' => $locked->getId()->toRfc4122(),
                            'package_id' => $locked->getPackage()->getId()->toRfc4122(),
                            'status' => $locked->getStatus()->value,
                        ],
                        captureRequestHashes: false,
                    ), false);
                    $this->entityManager->flush();
                }

                return $locked;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    private function createLicense(
        AccessPackageVersion $version,
        AccessLicenseLicenseeType $licenseeType,
        ?User $licensee,
        ?Institution $institution,
        User $actor,
        AccessLicenseSourceType $sourceType,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil,
        ?int $seatLimit,
        string $reasonCode,
        ?string $externalReference,
    ): AccessLicense {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $externalReference = $this->normalizeExternalReference($externalReference);
        $versionId = $version->getId();
        $actorId = $actor->getId();
        $licenseeId = $licensee?->getId();
        $institutionId = $institution?->getId();
        $validFrom = UtcInstant::ensure($validFrom);
        $validUntil = UtcInstant::ensure($validUntil);

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $versionId,
                $licenseeType,
                $licenseeId,
                $institutionId,
                $actorId,
                $sourceType,
                $validFrom,
                $validUntil,
                $seatLimit,
                $reasonCode,
                $externalReference,
            ): AccessLicense {
                $lockedInstitution = null;
                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution
                        || InstitutionStatus::Active !== $lockedInstitution->getStatus()
                    ) {
                        throw AccessEntitlementException::notFound();
                    }
                }

                $lockedVersion = $this->findFreshVersion($versionId, LockMode::PESSIMISTIC_READ);
                if (!$lockedVersion instanceof AccessPackageVersion
                    || AccessPackageVersionStatus::Active !== $lockedVersion->getStatus()
                ) {
                    throw AccessEntitlementException::invalidInput('License requires an active package version.');
                }
                $package = $this->findFreshPackage($lockedVersion->getPackage()->getId(), LockMode::PESSIMISTIC_READ);
                if (!$package instanceof AccessPackage || !$package->getStatus()->allowsNewLicense()) {
                    throw AccessEntitlementException::packageRetired();
                }

                if (AccessLicenseLicenseeType::User === $licenseeType
                    && AccessPackageTargetType::Individual !== $package->getTargetType()
                ) {
                    throw AccessEntitlementException::scopeMismatch('User license requires individual target package.');
                }
                if (AccessLicenseLicenseeType::Institution === $licenseeType
                    && AccessPackageTargetType::Institution !== $package->getTargetType()
                ) {
                    throw AccessEntitlementException::scopeMismatch('Institution license requires institution target package.');
                }

                $resolvedSeatLimit = $seatLimit;
                if (AccessLicenseLicenseeType::Institution === $licenseeType && null === $resolvedSeatLimit) {
                    $resolvedSeatLimit = $lockedVersion->getSeatLimit() ?? $package->getDefaultSeatLimit();
                }

                $userIds = [$actorId];
                if (null !== $licenseeId) {
                    $userIds[] = $licenseeId;
                }
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $freshLicensee = null;
                if (null !== $licenseeId) {
                    $freshLicensee = $users[$licenseeId->toRfc4122()] ?? null;
                    if (!$freshLicensee instanceof User) {
                        throw AccessEntitlementException::userNotFound();
                    }
                }

                if (AccessLicenseLicenseeType::User === $licenseeType) {
                    $this->authorization->assertCanManageUserLicenses($freshActor);
                } else {
                    if (!$lockedInstitution instanceof Institution) {
                        throw AccessEntitlementException::invalidInput();
                    }
                    $this->authorization->assertCanManageInstitutionLicenses($freshActor, $lockedInstitution);
                }

                $now = $this->utcNow();
                $license = AccessLicense::createPending(
                    $package,
                    $lockedVersion,
                    $licenseeType,
                    $freshLicensee,
                    $lockedInstitution,
                    $sourceType,
                    $externalReference,
                    $validFrom,
                    $validUntil,
                    $resolvedSeatLimit,
                    $lockedVersion->getPolicyHash(),
                    $freshActor,
                    $now,
                );
                $this->licenses->save($license, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AccessLicenseCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_license_manager',
                        'reason_code' => $reasonCode,
                        'license_id' => $license->getId()->toRfc4122(),
                        'package_id' => $package->getId()->toRfc4122(),
                        'package_version_id' => $lockedVersion->getId()->toRfc4122(),
                        'version_number' => $lockedVersion->getVersionNumber(),
                        'licensee_type' => $licenseeType->value,
                        'source_type' => $sourceType->value,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'seat_limit' => $license->getSeatLimit(),
                        'policy_hash' => $license->getPolicySnapshotHash(),
                        'external_reference' => $externalReference,
                        'status' => $license->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $license;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    /**
     * @param callable(AccessLicense, User, \DateTimeImmutable): void $mutator
     */
    private function mutate(
        AccessLicense $license,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
    ): AccessLicense {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $licenseId = $license->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $licenseId,
                $actorId,
                $reasonCode,
                $action,
                $mutator,
            ): AccessLicense {
                $locked = $this->findFreshLicense($licenseId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof AccessLicense) {
                    throw AccessEntitlementException::notFound();
                }

                $lockedInstitution = null;
                if ($locked->getInstitution() instanceof Institution) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $locked->getInstitution()->getId(),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }

                if (AccessLicenseLicenseeType::User === $locked->getLicenseeType()) {
                    $this->authorization->assertCanManageUserLicenses($freshActor);
                } else {
                    if (!$lockedInstitution instanceof Institution) {
                        throw AccessEntitlementException::notFound();
                    }
                    $this->authorization->assertCanManageInstitutionLicenses($freshActor, $lockedInstitution);
                }

                $now = $this->utcNow();
                if ((AccessLicenseStatus::Active === $locked->getStatus() || AccessLicenseStatus::Suspended === $locked->getStatus())
                    && $now >= $locked->getValidUntil()
                ) {
                    $locked->markExpired($now);
                    $this->entityManager->flush();
                    throw AccessEntitlementException::invalidTransition();
                }

                $mutator($locked, $freshActor, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'access_license_manager',
                        'reason_code' => $reasonCode,
                        'license_id' => $locked->getId()->toRfc4122(),
                        'package_id' => $locked->getPackage()->getId()->toRfc4122(),
                        'licensee_type' => $locked->getLicenseeType()->value,
                        'status' => $locked->getStatus()->value,
                        'institution_id' => $locked->getInstitution()?->getId()->toRfc4122(),
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

    private function findFreshLicense(Uuid $id, LockMode $lockMode): ?AccessLicense
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(AccessLicense::class, 'l')
            ->where('l.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode)
            ->getOneOrNullResult();

        return $entity instanceof AccessLicense ? $entity : null;
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

    private function normalizeExternalReference(?string $externalReference): ?string
    {
        if (null === $externalReference) {
            return null;
        }
        $externalReference = trim($externalReference);
        if ('' === $externalReference) {
            return null;
        }
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $externalReference)) {
            throw AccessEntitlementException::invalidInput('external_reference must be a non-PII code.');
        }

        return $externalReference;
    }
}
