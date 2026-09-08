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
use App\Exception\InstitutionMembershipException;
use App\Repository\InstitutionMembershipRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Institution-scoped membership mutations. Global ROLE_* never substitutes for membership.
 *
 * Lock order: institution (WRITE) → users by UUID (READ) → membership (WRITE when mutating an existing row).
 */
final class InstitutionMembershipManager
{
    public function __construct(
        private readonly InstitutionMembershipRepository $memberships,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalUserReloader $userReloader,
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

        $institutionId = $institution->getId();
        $actorId = $actor->getId();
        $subjectId = $subject->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($institutionId, $actorId, $subjectId, $role, $reasonCode): InstitutionMembership {
                $lockedInstitution = $this->lockInstitutionById($institutionId);
                $this->assertInstitutionAllowsMembershipOps($lockedInstitution);

                $users = $this->userReloader->lockExistingByIds([$actorId, $subjectId]);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw InstitutionMembershipException::userNotFound();
                }
                $freshSubject = $users[$subjectId->toRfc4122()] ?? null;
                if (!$freshSubject instanceof User) {
                    throw InstitutionMembershipException::userNotFound();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshSubject)) {
                    throw InstitutionMembershipException::invalidInput('Subject user must be active and verified.');
                }

                $this->assertActorMayManageRole($freshActor, $lockedInstitution, $role, null);

                if (null !== $this->memberships->findMembership($freshSubject, $lockedInstitution)) {
                    throw InstitutionMembershipException::duplicateMembership();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $membership = InstitutionMembership::createActive($lockedInstitution, $freshSubject, $role, $now);
                $this->memberships->save($membership, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionMemberAdded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $freshSubject,
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

        $this->mutateExisting(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            requireSubjectActiveVerified: true,
            mutator: function (
                Institution $lockedInstitution,
                InstitutionMembership $locked,
                User $freshActor,
                User $freshSubject,
                string $reasonCode,
            ) use ($newRole): void {
                $this->assertActorMayManageRole($freshActor, $lockedInstitution, $newRole, $locked);

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
                    actorUser: $freshActor,
                    subjectUser: $freshSubject,
                    metadata: [
                        'source' => 'institution_membership_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'previous_membership_role' => $previous->value,
                        'new_membership_role' => $newRole->value,
                    ],
                    captureRequestHashes: false,
                ), false);
            },
        );
    }

    public function suspend(InstitutionMembership $membership, User $actor, string $reasonCode): void
    {
        $this->mutateStatus(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            action: SecurityAuditAction::InstitutionMemberSuspended,
            requireSubjectActiveVerified: false,
            protectLastOwner: true,
            mutator: static function (InstitutionMembership $locked, \DateTimeImmutable $now): void {
                $locked->suspend($now);
            },
        );
    }

    public function reactivate(InstitutionMembership $membership, User $actor, string $reasonCode): void
    {
        $this->mutateStatus(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            action: SecurityAuditAction::InstitutionMemberReactivated,
            requireSubjectActiveVerified: true,
            protectLastOwner: false,
            mutator: static function (InstitutionMembership $locked, \DateTimeImmutable $now): void {
                $locked->reactivate($now);
            },
        );
    }

    public function endMembership(InstitutionMembership $membership, User $actor, string $reasonCode): void
    {
        $this->mutateStatus(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            action: SecurityAuditAction::InstitutionMemberEnded,
            requireSubjectActiveVerified: false,
            protectLastOwner: true,
            mutator: static function (InstitutionMembership $locked, \DateTimeImmutable $now): void {
                $locked->end($now);
            },
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
        bool $requireSubjectActiveVerified,
        bool $protectLastOwner,
        callable $mutator,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        $this->mutateExisting(
            membership: $membership,
            actor: $actor,
            reasonCode: $reasonCode,
            requireSubjectActiveVerified: $requireSubjectActiveVerified,
            mutator: function (
                Institution $lockedInstitution,
                InstitutionMembership $locked,
                User $freshActor,
                User $freshSubject,
                string $reasonCode,
            ) use ($action, $mutator, $protectLastOwner): void {
                $this->assertActorMayManageExisting($freshActor, $lockedInstitution, $locked);

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
                    actorUser: $freshActor,
                    subjectUser: $freshSubject,
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
            },
        );
    }

    /**
     * @param callable(Institution, InstitutionMembership, User, User, string): void $mutator
     */
    private function mutateExisting(
        InstitutionMembership $membership,
        User $actor,
        string $reasonCode,
        bool $requireSubjectActiveVerified,
        callable $mutator,
    ): void {
        $institutionId = $membership->getInstitution()->getId();
        $membershipId = $membership->getId();
        $subjectUserId = $membership->getUser()->getId();
        $actorId = $actor->getId();

        // Never trust associations on the caller-provided membership instance inside the TX.
        if ($this->entityManager->contains($membership)) {
            $this->entityManager->detach($membership);
        }

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $institutionId,
                $membershipId,
                $subjectUserId,
                $actorId,
                $reasonCode,
                $requireSubjectActiveVerified,
                $mutator,
            ): void {
                $lockedInstitution = $this->lockInstitutionById($institutionId);
                $this->assertInstitutionAllowsMembershipOps($lockedInstitution);

                $users = $this->userReloader->lockExistingByIds([$actorId, $subjectUserId]);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw InstitutionMembershipException::userNotFound();
                }
                $freshSubject = $users[$subjectUserId->toRfc4122()] ?? null;
                if (!$freshSubject instanceof User) {
                    throw InstitutionMembershipException::userNotFound();
                }
                if ($requireSubjectActiveVerified && !$this->activeVerifiedUserPolicy->isActiveAndVerified($freshSubject)) {
                    throw InstitutionMembershipException::invalidInput('Subject user must be active and verified.');
                }

                $locked = $this->lockMembershipById($membershipId);
                $this->assertSameInstitution($locked, $lockedInstitution);
                if (!$locked->getUser()->getId()->equals($freshSubject->getId())) {
                    throw InstitutionMembershipException::conflict();
                }

                $mutator($lockedInstitution, $locked, $freshActor, $freshSubject, $reasonCode);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionMembershipException::conflict();
        }
    }

    private function lockInstitutionById(Uuid $institutionId): Institution
    {
        $locked = $this->entityManager->find(Institution::class, $institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Institution) {
            throw InstitutionMembershipException::notFound();
        }

        return $locked;
    }

    private function lockMembershipById(Uuid $membershipId): InstitutionMembership
    {
        $locked = $this->entityManager->find(InstitutionMembership::class, $membershipId, LockMode::PESSIMISTIC_WRITE);
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
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw InstitutionMembershipException::unauthorized();
        }

        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
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

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw InstitutionMembershipException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}
