<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AcademicYear;
use App\Entity\Institution;
use App\Entity\InstitutionActiveAcademicYearGuard;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AcademicYearException;
use App\Exception\InstitutionOperationException;
use App\Repository\AcademicYearRepository;
use App\Repository\InstitutionActiveAcademicYearGuardRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Academic year lifecycle. Activate closes any previous active year in the same transaction.
 *
 * Lock order: Institution → AcademicYear → Users → Membership → Guards.
 */
final class AcademicYearManager
{
    public function __construct(
        private readonly AcademicYearRepository $years,
        private readonly InstitutionActiveAcademicYearGuardRepository $activeGuards,
        private readonly InstitutionNameNormalizer $nameNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createPlanned(
        Institution $institution,
        User $actor,
        string $name,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $reasonCode,
    ): AcademicYear {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $names = $this->normalizeName($name);
        $startsAt = $this->asDate($startsAt);
        $endsAt = $this->asDate($endsAt);
        if ($startsAt > $endsAt) {
            throw AcademicYearException::invalidInput('startsAt must be on or before endsAt.');
        }

        $institutionId = $institution->getId();
        $actorId = $actor->getId();

        try {
            $year = $this->entityManager->wrapInTransaction(function () use (
                $institutionId,
                $actorId,
                $names,
                $startsAt,
                $endsAt,
                $reasonCode,
            ): AcademicYear {
                $lockedInstitution = $this->lockInstitution($institutionId);
                $this->assertInstitutionActive($lockedInstitution);
                $freshActor = $this->lockActor($actorId);
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                if ($this->years->hasOverlappingRange($lockedInstitution, $startsAt, $endsAt)) {
                    throw AcademicYearException::dateOverlap();
                }
                if ($this->years->existsWithNormalizedName($lockedInstitution, $names['normalizedName'])) {
                    throw AcademicYearException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $year = AcademicYear::createPlanned(
                    $lockedInstitution,
                    $names['name'],
                    $names['normalizedName'],
                    $startsAt,
                    $endsAt,
                    $now,
                );
                $this->years->save($year, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AcademicYearCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'academic_year_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $year->getId()->toRfc4122(),
                        'new_status' => $year->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $year;
            });
        } catch (UniqueConstraintViolationException) {
            throw AcademicYearException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw AcademicYearException::conflict();
        }

        $this->authCache->invalidateInstitution($institutionId);

        return $year;
    }

    public function activate(AcademicYear $year, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $yearId = $year->getId();
        $institutionId = $year->getInstitution()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($yearId, $institutionId, $actorId, $reasonCode): void {
                $lockedInstitution = $this->lockInstitution($institutionId);
                $this->assertInstitutionActive($lockedInstitution);

                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedYear instanceof AcademicYear) {
                    throw AcademicYearException::notFound();
                }
                if (!$lockedYear->getInstitution()->getId()->equals($lockedInstitution->getId())) {
                    throw AcademicYearException::crossInstitution();
                }

                $freshActor = $this->lockActor($actorId);
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $previousGuard = $this->freshEntities->findFreshLockedActiveAcademicYearGuard(
                    $lockedInstitution->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );

                if ($previousGuard instanceof InstitutionActiveAcademicYearGuard) {
                    $previousYear = $previousGuard->getAcademicYear();
                    if (!$previousYear->getId()->equals($lockedYear->getId())) {
                        $previousLocked = $this->freshEntities->findFreshLockedAcademicYear(
                            $previousYear->getId(),
                            LockMode::PESSIMISTIC_WRITE,
                        );
                        if (!$previousLocked instanceof AcademicYear) {
                            throw AcademicYearException::conflict();
                        }
                        if (AcademicYearStatus::Active === $previousLocked->getStatus()) {
                            $oldStatus = $previousLocked->getStatus()->value;
                            $previousLocked->close($now);
                            $this->years->save($previousLocked, false);
                            $this->auditRecorder->record(new SecurityAuditContext(
                                action: SecurityAuditAction::AcademicYearClosed,
                                actorType: SecurityAuditActorType::User,
                                outcome: SecurityAuditOutcome::Success,
                                actorUser: $freshActor,
                                metadata: [
                                    'source' => 'academic_year_manager',
                                    'reason_code' => $reasonCode,
                                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                                    'academic_year_id' => $previousLocked->getId()->toRfc4122(),
                                    'old_status' => $oldStatus,
                                    'new_status' => $previousLocked->getStatus()->value,
                                ],
                                captureRequestHashes: false,
                            ), false);
                        }
                        $previousGuard->swapTo($lockedYear);
                        $this->activeGuards->save($previousGuard, false);
                    }
                } else {
                    $guard = InstitutionActiveAcademicYearGuard::bind($lockedInstitution, $lockedYear);
                    $this->activeGuards->save($guard, false);
                }

                $oldStatus = $lockedYear->getStatus()->value;
                $lockedYear->activate($now);
                $this->years->save($lockedYear, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AcademicYearActivated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'academic_year_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedYear->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw AcademicYearException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw AcademicYearException::conflict();
        }

        $this->authCache->invalidateInstitution($institutionId);
    }

    public function close(AcademicYear $year, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $yearId = $year->getId();
        $institutionId = $year->getInstitution()->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($yearId, $institutionId, $actorId, $reasonCode): void {
                $lockedInstitution = $this->lockInstitution($institutionId);
                // Close is allowed for authorized actors even if institution is not active? Spec: "Close anytime by authorized."
                // But institution archived should still allow? "Close anytime by authorized." - only check actor auth, not institution active.
                if (InstitutionStatus::Archived === $lockedInstitution->getStatus()) {
                    throw AcademicYearException::institutionNotOperable();
                }

                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedYear instanceof AcademicYear) {
                    throw AcademicYearException::notFound();
                }
                if (!$lockedYear->getInstitution()->getId()->equals($lockedInstitution->getId())) {
                    throw AcademicYearException::crossInstitution();
                }

                $freshActor = $this->lockActor($actorId);
                $this->assertActorMayManage($freshActor, $lockedInstitution);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $oldStatus = $lockedYear->getStatus()->value;
                $wasActive = AcademicYearStatus::Active === $lockedYear->getStatus();
                $lockedYear->close($now);
                $this->years->save($lockedYear, false);

                if ($wasActive) {
                    $guard = $this->freshEntities->findFreshLockedActiveAcademicYearGuard(
                        $lockedInstitution->getId(),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if ($guard instanceof InstitutionActiveAcademicYearGuard
                        && $guard->getAcademicYear()->getId()->equals($lockedYear->getId())) {
                        $this->activeGuards->remove($guard, false);
                    }
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AcademicYearClosed,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'academic_year_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'academic_year_id' => $lockedYear->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedYear->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw AcademicYearException::conflict();
        }

        $this->authCache->invalidateInstitution($institutionId);
    }

    private function lockInstitution(Uuid $institutionId): Institution
    {
        $locked = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Institution) {
            throw AcademicYearException::notFound();
        }

        return $locked;
    }

    private function lockActor(Uuid $actorId): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw AcademicYearException::userNotFound();
        }

        return $freshActor;
    }

    private function assertInstitutionActive(Institution $institution): void
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw AcademicYearException::institutionNotOperable();
        }
    }

    private function assertActorMayManage(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AcademicYearException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        $actorMembership = $this->freshEntities->findFreshMembershipForUser(
            $actor->getId(),
            $institution->getId(),
        );
        if (!$actorMembership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $actorMembership->getStatus()) {
            throw AcademicYearException::unauthorized();
        }

        $role = $actorMembership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw AcademicYearException::unauthorized();
        }
    }

    /**
     * @return array{name: string, normalizedName: string}
     */
    private function normalizeName(string $name): array
    {
        try {
            $names = $this->nameNormalizer->normalize($name);
        } catch (InstitutionOperationException $e) {
            throw AcademicYearException::invalidInput($e->getMessage());
        }

        return [
            'name' => $names['name'],
            'normalizedName' => $names['normalizedName'],
        ];
    }

    private function asDate(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTime(0, 0, 0);
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw AcademicYearException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
