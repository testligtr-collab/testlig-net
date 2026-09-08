<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionMembershipException;
use App\Repository\InstitutionMembershipRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Institution-scoped membership mutations. Global ROLE_* never substitutes for membership.
 */
final class InstitutionMembershipManager
{
    public function __construct(
        private readonly InstitutionMembershipRepository $memberships,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function addMember(
        Institution $institution,
        User $actor,
        User $subject,
        InstitutionMembershipRole $role,
        string $reasonCode,
    ): InstitutionMembership {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        if (InstitutionMembershipRole::Owner === $role) {
            throw InstitutionMembershipException::ownerRoleRestricted();
        }
        $this->assertActiveVerifiedUser($subject);

        try {
            return $this->entityManager->wrapInTransaction(function () use ($institution, $actor, $subject, $role, $reasonCode): InstitutionMembership {
                $lockedInstitution = $this->lockInstitution($institution);
                $this->assertInstitutionAllowsMembershipOps($lockedInstitution);
                $this->assertActorMayManageRole($actor, $lockedInstitution, $role, null);

                if (null !== $this->memberships->findMembership($subject, $lockedInstitution)) {
                    throw InstitutionMembershipException::duplicateMembership();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $membership = InstitutionMembership::createActive($lockedInstitution, $subject, $role, $now);
                $this->memberships->save($membership, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionMemberAdded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    subjectUser: $subject,
                    metadata: [
                        'source' => 'institution_membership_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'membership_role' => $role->value,
                        'new_status' => $membership->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $membership;
            });
        } catch (UniqueConstraintViolationException) {
            throw InstitutionMembershipException::duplicateMembership();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionMembershipException::conflict();
        }
    }

    public function changeRole(
        InstitutionMembership $membership,
        User $actor,
        InstitutionMembershipRole $newRole,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        if (InstitutionMembershipRole::Owner === $newRole) {
            throw InstitutionMembershipException::ownerRoleRestricted();
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($membership, $actor, $newRole, $reasonCode): void {
                $lockedInstitution = $this->lockInstitution($membership->getInstitution());
                $locked = $this->lockMembership($membership);
                $this->assertSameInstitution($locked, $lockedInstitution);
                $this->assertInstitutionAllowsMembershipOps($lockedInstitution);
                $this->assertActorMayManageRole($actor, $lockedInstitution, $newRole, $locked);

                if (InstitutionMembershipRole::Owner === $locked->getRole()) {
                    throw InstitutionMembershipException::ownerRoleRestricted();
                }

                $previous = $locked->getRole();
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $locked->changeRole($newRole, $now);
                $this->memberships->save($locked, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionMemberRoleChanged,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    subjectUser: $locked->getUser(),
                    metadata: [
                        'source' => 'institution_membership_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'previous_membership_role' => $previous->value,
                        'new_membership_role' => $newRole->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionMembershipException::conflict();
        }
    }

    public function suspend(InstitutionMembership $membership, User $actor, string $reasonCode): void
    {
        $this->mutateStatus(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            action: SecurityAuditAction::InstitutionMemberSuspended,
            mutator: static function (InstitutionMembership $locked, \DateTimeImmutable $now): void {
                $locked->suspend($now);
            },
            protectLastOwner: true,
        );
    }

    public function reactivate(InstitutionMembership $membership, User $actor, string $reasonCode): void
    {
        $this->mutateStatus(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            action: SecurityAuditAction::InstitutionMemberReactivated,
            mutator: static function (InstitutionMembership $locked, \DateTimeImmutable $now): void {
                $locked->reactivate($now);
            },
            protectLastOwner: false,
        );
    }

    public function endMembership(InstitutionMembership $membership, User $actor, string $reasonCode): void
    {
        $this->mutateStatus(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            action: SecurityAuditAction::InstitutionMemberEnded,
            mutator: static function (InstitutionMembership $locked, \DateTimeImmutable $now): void {
                $locked->end($now);
            },
            protectLastOwner: true,
        );
    }

    /**
     * @param callable(InstitutionMembership, \DateTimeImmutable): void $mutator
     */
    private function mutateStatus(
        InstitutionMembership $membership,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
        bool $protectLastOwner,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        try {
            $this->entityManager->wrapInTransaction(function () use ($membership, $actor, $reasonCode, $action, $mutator, $protectLastOwner): void {
                $lockedInstitution = $this->lockInstitution($membership->getInstitution());
                $locked = $this->lockMembership($membership);
                $this->assertSameInstitution($locked, $lockedInstitution);
                $this->assertInstitutionAllowsMembershipOps($lockedInstitution);
                $this->assertActorMayManageExisting($actor, $lockedInstitution, $locked);

                if ($protectLastOwner
                    && InstitutionMembershipRole::Owner === $locked->getRole()
                    && InstitutionMembershipStatus::Active === $locked->getStatus()
                    && $this->memberships->countActiveOwners($lockedInstitution) <= 1) {
                    throw InstitutionMembershipException::lastOwnerProtected();
                }

                $previous = $locked->getStatus();
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $mutator($locked, $now);
                $this->memberships->save($locked, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    subjectUser: $locked->getUser(),
                    metadata: [
                        'source' => 'institution_membership_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'membership_role' => $locked->getRole()->value,
                        'previous_status' => $previous->value,
                        'new_status' => $locked->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionMembershipException::conflict();
        }
    }

    private function lockInstitution(Institution $institution): Institution
    {
        $locked = $this->entityManager->find(Institution::class, $institution->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Institution) {
            throw InstitutionMembershipException::notFound();
        }

        return $locked;
    }

    private function lockMembership(InstitutionMembership $membership): InstitutionMembership
    {
        $locked = $this->entityManager->find(InstitutionMembership::class, $membership->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof InstitutionMembership) {
            throw InstitutionMembershipException::notFound();
        }

        return $locked;
    }

    private function assertSameInstitution(InstitutionMembership $membership, Institution $institution): void
    {
        if (!$membership->getInstitution()->getId()->equals($institution->getId())) {
            throw InstitutionMembershipException::crossInstitution();
        }
    }

    private function assertInstitutionAllowsMembershipOps(Institution $institution): void
    {
        if (InstitutionStatus::Archived === $institution->getStatus()) {
            throw InstitutionMembershipException::institutionNotOperable();
        }
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw InstitutionMembershipException::institutionNotOperable();
        }
    }

    private function assertActorMayManageRole(
        User $actor,
        Institution $institution,
        InstitutionMembershipRole $targetRole,
        ?InstitutionMembership $targetMembership,
    ): void {
        if ($this->isSuperAdmin($actor)) {
            if (null !== $targetMembership && $actor->getId()->equals($targetMembership->getUser()->getId())
                && InstitutionMembershipRole::Owner !== $targetMembership->getRole()
                && InstitutionMembershipRole::Owner === $targetRole) {
                throw InstitutionMembershipException::ownerRoleRestricted();
            }

            return;
        }

        $actorMembership = $this->memberships->findActiveMembership($actor, $institution);
        if (!$actorMembership instanceof InstitutionMembership) {
            throw InstitutionMembershipException::unauthorized();
        }

        if (null !== $targetMembership && $actor->getId()->equals($targetMembership->getUser()->getId())) {
            throw InstitutionMembershipException::unauthorized();
        }

        $actorRole = $actorMembership->getRole();
        if (InstitutionMembershipRole::Owner === $actorRole) {
            if (!\in_array($targetRole, InstitutionMembershipRole::assignableByOwner(), true)) {
                throw InstitutionMembershipException::unauthorized();
            }
            if (null !== $targetMembership && InstitutionMembershipRole::Owner === $targetMembership->getRole()) {
                throw InstitutionMembershipException::unauthorized();
            }

            return;
        }

        if (InstitutionMembershipRole::Manager === $actorRole) {
            if (!\in_array($targetRole, InstitutionMembershipRole::assignableByManager(), true)) {
                throw InstitutionMembershipException::unauthorized();
            }
            if (null !== $targetMembership) {
                $existing = $targetMembership->getRole();
                if (InstitutionMembershipRole::Owner === $existing || InstitutionMembershipRole::Manager === $existing) {
                    throw InstitutionMembershipException::unauthorized();
                }
            }

            return;
        }

        throw InstitutionMembershipException::unauthorized();
    }

    private function assertActorMayManageExisting(User $actor, Institution $institution, InstitutionMembership $target): void
    {
        $this->assertActorMayManageRole($actor, $institution, $target->getRole(), $target);
    }

    private function assertActiveVerifiedUser(User $user): void
    {
        if (UserStatus::Active !== $user->getStatus() || null === $user->getEmailVerifiedAt()) {
            throw InstitutionMembershipException::invalidInput('Subject user must be active and verified.');
        }
    }

    private function isSuperAdmin(User $user): bool
    {
        return \in_array(UserRole::SuperAdmin->value, $user->getRoles(), true);
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw InstitutionMembershipException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
