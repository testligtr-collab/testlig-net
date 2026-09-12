<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\AccessLicense;
use App\Entity\Institution;
use App\Entity\InstitutionLicenseSeat;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AccessLicenseLicenseeType;
use App\Enum\AccessLicenseStatus;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AccessEntitlementException;
use App\Repository\InstitutionLicenseSeatRepository;
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
 * Institution license seat assign/revoke.
 *
 * Lock order: Institution → License → User/Membership → Seat → Audit
 * Pending licenses cannot receive seats; revoked seats are not reactivated.
 */
final class InstitutionLicenseSeatManager
{
    public function __construct(
        private readonly InstitutionLicenseSeatRepository $seats,
        private readonly AccessPackageAuthorization $authorization,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function assign(
        AccessLicense $license,
        InstitutionMembership $membership,
        User $actor,
        string $reasonCode,
    ): InstitutionLicenseSeat {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $licenseId = $license->getId();
        $membershipId = $membership->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $licenseId,
                $membershipId,
                $actorId,
                $reasonCode,
            ): InstitutionLicenseSeat {
                $lockedLicense = $this->findFreshLicense($licenseId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedLicense instanceof AccessLicense) {
                    throw AccessEntitlementException::notFound();
                }
                if (AccessLicenseLicenseeType::Institution !== $lockedLicense->getLicenseeType()) {
                    throw AccessEntitlementException::scopeMismatch('Seats require an institution license.');
                }

                $institution = $lockedLicense->getInstitution();
                if (!$institution instanceof Institution) {
                    throw AccessEntitlementException::notFound();
                }
                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                    $institution->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedInstitution instanceof Institution
                    || InstitutionStatus::Active !== $lockedInstitution->getStatus()
                ) {
                    throw AccessEntitlementException::notFound();
                }

                $lockedLicense = $this->findFreshLicense($licenseId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedLicense instanceof AccessLicense) {
                    throw AccessEntitlementException::notFound();
                }
                if (AccessLicenseStatus::Pending === $lockedLicense->getStatus()) {
                    throw AccessEntitlementException::invalidTransition();
                }
                $now = UtcInstant::ensure($this->utcNow());
                if ((AccessLicenseStatus::Active === $lockedLicense->getStatus()
                        || AccessLicenseStatus::Suspended === $lockedLicense->getStatus())
                    && $now >= $lockedLicense->getValidUntil()
                ) {
                    $lockedLicense->markExpired($now);
                    $this->entityManager->flush();
                    throw AccessEntitlementException::invalidTransition();
                }
                if (AccessLicenseStatus::Active !== $lockedLicense->getStatus()) {
                    throw AccessEntitlementException::invalidTransition();
                }
                if (!$lockedLicense->isWithinValidityWindow($now)) {
                    throw AccessEntitlementException::invalidTransition();
                }

                $lockedMembership = $this->freshEntities->findFreshLockedMembership(
                    $membershipId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedMembership instanceof InstitutionMembership
                    || InstitutionMembershipStatus::Active !== $lockedMembership->getStatus()
                    || !$lockedMembership->getInstitution()->getId()->equals($lockedInstitution->getId())
                ) {
                    throw AccessEntitlementException::scopeMismatch('Membership must be active in the license institution.');
                }

                $seatUserId = $lockedMembership->getUser()->getId();
                $users = $this->freshEntities->findFreshLockedUsers(
                    [$actorId, $seatUserId],
                    LockMode::PESSIMISTIC_READ,
                );
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                $freshSeatUser = $users[$seatUserId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User || !$freshSeatUser instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanManageSeats($freshActor, $lockedInstitution);

                $existing = $this->seats->findActiveForLicenseAndMembership($licenseId, $membershipId);
                if ($existing instanceof InstitutionLicenseSeat) {
                    throw AccessEntitlementException::conflict();
                }

                $seatLimit = $lockedLicense->getSeatLimit();
                $activeCount = $this->seats->countActiveForLicense($licenseId);
                if (null !== $seatLimit && $activeCount >= $seatLimit) {
                    throw AccessEntitlementException::seatLimitExceeded();
                }

                $seat = InstitutionLicenseSeat::assign(
                    $lockedLicense,
                    $lockedInstitution,
                    $lockedMembership,
                    $freshSeatUser,
                    $freshActor,
                    $now,
                );
                $this->seats->save($seat, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionLicenseSeatAssigned,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'institution_license_seat_manager',
                        'reason_code' => $reasonCode,
                        'seat_id' => $seat->getId()->toRfc4122(),
                        'license_id' => $licenseId->toRfc4122(),
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'membership_id' => $membershipId->toRfc4122(),
                        'seat_count' => $activeCount + 1,
                        'seat_limit' => $seatLimit,
                        'status' => $seat->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $seat;
            });
        } catch (AccessEntitlementException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AccessEntitlementException::conflict();
        }
    }

    public function revoke(
        InstitutionLicenseSeat $seat,
        User $actor,
        string $reasonCode,
    ): InstitutionLicenseSeat {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $seatId = $seat->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $seatId,
                $actorId,
                $reasonCode,
            ): InstitutionLicenseSeat {
                $lockedSeat = $this->findFreshSeat($seatId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedSeat instanceof InstitutionLicenseSeat) {
                    throw AccessEntitlementException::notFound();
                }

                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                    $lockedSeat->getInstitution()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedInstitution instanceof Institution) {
                    throw AccessEntitlementException::notFound();
                }

                $lockedLicense = $this->findFreshLicense($lockedSeat->getLicense()->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$lockedLicense instanceof AccessLicense) {
                    throw AccessEntitlementException::notFound();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AccessEntitlementException::userNotFound();
                }
                $this->authorization->assertCanManageSeats($freshActor, $lockedInstitution);

                $now = $this->utcNow();
                $lockedSeat->revoke($freshActor, $reasonCode, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionLicenseSeatRevoked,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'institution_license_seat_manager',
                        'reason_code' => $reasonCode,
                        'seat_id' => $seatId->toRfc4122(),
                        'license_id' => $lockedLicense->getId()->toRfc4122(),
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'membership_id' => $lockedSeat->getMembership()->getId()->toRfc4122(),
                        'status' => $lockedSeat->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $lockedSeat;
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

    private function findFreshSeat(Uuid $id, LockMode $lockMode): ?InstitutionLicenseSeat
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(InstitutionLicenseSeat::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode)
            ->getOneOrNullResult();

        return $entity instanceof InstitutionLicenseSeat ? $entity : null;
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
