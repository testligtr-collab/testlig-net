<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserManagementFailureReason;
use App\Enum\UserStatus;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Controlled account status mutations (suspend / archive / controlled reactivation).
 *
 * E-mail verification activation remains in UserAccountLifecycle. Mutations always
 * authorize against fresh locked User rows (HINT_REFRESH).
 */
final class UserStatusManager
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly FreshUserLoader $freshUsers,
        private readonly PrivilegedUserActorGuard $actorGuard,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function suspend(User $user, User $actor, string $reason): void
    {
        $this->transition($user, UserStatus::Suspended, $actor, $reason, 'suspend');
    }

    public function archive(User $user, User $actor, string $reason): void
    {
        $this->transition($user, UserStatus::Archived, $actor, $reason, 'archive');
    }

    /**
     * Controlled reactivation: only suspended → active (evaluated on fresh locked state).
     */
    public function reactivate(User $user, User $actor, string $reason): void
    {
        $this->transition($user, UserStatus::Active, $actor, $reason, 'reactivate');
    }

    private function transition(User $user, UserStatus $target, User $actor, string $reason, string $source): void
    {
        $reason = $this->normalizeReason($reason);
        $actorId = $actor->getId();
        $targetId = $user->getId();
        if ($actorId->equals($targetId)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::SelfManagementForbidden,
                'Actors cannot change their own account status.',
            );
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($actorId, $targetId, $target, $reason, $source): void {
                [$freshActor, $freshTarget] = $this->lockActorAndTarget($actorId, $targetId);
                $this->actorGuard->assertActiveVerifiedPrivilegedActor($freshActor);
                $this->assertMayMutateTargetStatus($freshActor, $freshTarget);

                $previous = $freshTarget->getStatus();
                if ('reactivate' === $source) {
                    if (UserStatus::Suspended !== $previous) {
                        throw InvalidUserTransitionException::forStatus($previous, UserStatus::Active);
                    }
                    if (null === $freshTarget->getEmailVerifiedAt()) {
                        throw InvalidUserTransitionException::management(
                            UserManagementFailureReason::InvalidTransition,
                            'Unverified accounts cannot be reactivated to active.',
                        );
                    }
                }

                $freshTarget->transitionTo($target);
                $this->users->save($freshTarget, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::StatusChanged,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $freshTarget,
                    metadata: [
                        'reason' => $reason,
                        'previous_status' => $previous->value,
                        'new_status' => $target->value,
                        'source' => $source,
                    ],
                ), false);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::Conflict,
                'User status mutation could not complete due to a lock conflict. Retry later.',
            );
        }

        $this->authCache->invalidateUser($targetId);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function lockActorAndTarget(Uuid $actorId, Uuid $targetId): array
    {
        $locked = $this->freshUsers->findFreshLockedUsers(
            [$actorId, $targetId],
            LockMode::PESSIMISTIC_WRITE,
        );

        $freshActor = $locked[$actorId->toRfc4122()] ?? null;
        $freshTarget = $locked[$targetId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User || !$freshTarget instanceof User) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::UserNotFound,
                'User was not found.',
            );
        }

        return [$freshActor, $freshTarget];
    }

    private function assertMayMutateTargetStatus(User $freshActor, User $freshTarget): void
    {
        if ($this->actorGuard->isSuperAdmin($freshTarget)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::ProtectedSuperAdmin,
                'ROLE_SUPER_ADMIN accounts cannot be managed through UserStatusManager.',
            );
        }

        if ($this->actorGuard->isPlainAdmin($freshActor) && $this->actorGuard->isAdmin($freshTarget)) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::TargetPrivilegeTooHigh,
                'ROLE_ADMIN actors cannot manage another ROLE_ADMIN account status.',
            );
        }
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw InvalidUserTransitionException::forRole('A non-empty reason is required for status changes.');
        }

        return mb_substr($reason, 0, 255);
    }
}
