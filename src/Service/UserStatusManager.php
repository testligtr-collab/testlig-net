<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlled account status mutations (suspend / archive / controlled reactivation).
 *
 * E-mail verification activation remains in UserAccountLifecycle.
 */
final class UserStatusManager
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityAuditRecorder $auditRecorder,
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
     * Controlled reactivation: only suspended → active.
     */
    public function reactivate(User $user, User $actor, string $reason): void
    {
        if (UserStatus::Suspended !== $user->getStatus()) {
            throw InvalidUserTransitionException::forStatus($user->getStatus(), UserStatus::Active);
        }

        $this->transition($user, UserStatus::Active, $actor, $reason, 'reactivate');
    }

    private function transition(User $user, UserStatus $target, User $actor, string $reason, string $source): void
    {
        $this->assertActorMayManageStatus($actor);
        $reason = $this->normalizeReason($reason);
        $previous = $user->getStatus();

        $this->entityManager->wrapInTransaction(function () use ($user, $target, $actor, $reason, $previous, $source): void {
            $user->transitionTo($target);
            $this->users->save($user, false);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::StatusChanged,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                subjectUser: $user,
                metadata: [
                    'reason' => $reason,
                    'previous_status' => $previous->value,
                    'new_status' => $target->value,
                    'source' => $source,
                ],
            ), false);
            $this->entityManager->flush();
        });
    }

    private function assertActorMayManageStatus(User $actor): void
    {
        $roles = $actor->getRoles();
        if (!\in_array(UserRole::Admin->value, $roles, true)
            && !\in_array(UserRole::SuperAdmin->value, $roles, true)) {
            throw InvalidUserTransitionException::forRole(
                'Only ROLE_ADMIN or ROLE_SUPER_ADMIN actors may change user status.'
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
