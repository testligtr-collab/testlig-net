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
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Controlled account status / verification mutations for application services.
 *
 * Email activation always re-reads the User under PESSIMISTIC_WRITE + HINT_REFRESH.
 * Login bookkeeping remains best-effort and does not invalidate auth cache.
 */
final class UserAccountLifecycle
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly FreshUserLoader $freshUsers,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function markEmailVerifiedAndActivate(User $user): void
    {
        $userId = $user->getId();
        $didMutate = false;

        try {
            $didMutate = $this->entityManager->wrapInTransaction(function () use ($userId): bool {
                $fresh = $this->freshUsers->findFreshLockedUser($userId, LockMode::PESSIMISTIC_WRITE);
                if (!$fresh instanceof User) {
                    throw InvalidUserTransitionException::management(
                        UserManagementFailureReason::UserNotFound,
                        'User was not found.',
                    );
                }

                if (UserStatus::Active === $fresh->getStatus() && null !== $fresh->getEmailVerifiedAt()) {
                    return false;
                }

                if (UserStatus::Suspended === $fresh->getStatus() || UserStatus::Archived === $fresh->getStatus()) {
                    throw InvalidUserTransitionException::management(
                        UserManagementFailureReason::InvalidTransition,
                        'Suspended or archived accounts cannot be activated through e-mail verification.',
                    );
                }

                if (UserStatus::Active === $fresh->getStatus() && null === $fresh->getEmailVerifiedAt()) {
                    // Unexpected: active without verification — only stamp verification, keep active.
                    $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                    $previous = $fresh->getStatus();
                    $fresh->markEmailVerified($now);
                    $this->users->save($fresh, false);
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::EmailVerified,
                        actorType: SecurityAuditActorType::System,
                        outcome: SecurityAuditOutcome::Success,
                        subjectUser: $fresh,
                        metadata: [
                            'source' => 'email_verification',
                            'previous_status' => $previous->value,
                            'new_status' => $fresh->getStatus()->value,
                            'note' => 'active_missing_verification_repaired',
                        ],
                    ), false);
                    $this->entityManager->flush();

                    return true;
                }

                if (UserStatus::PendingVerification !== $fresh->getStatus()) {
                    throw InvalidUserTransitionException::forStatus($fresh->getStatus(), UserStatus::Active);
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $previous = $fresh->getStatus();
                $fresh->markEmailVerified($now);
                $fresh->transitionTo(UserStatus::Active);
                $this->users->save($fresh, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::EmailVerified,
                    actorType: SecurityAuditActorType::System,
                    outcome: SecurityAuditOutcome::Success,
                    subjectUser: $fresh,
                    metadata: [
                        'source' => 'email_verification',
                        'previous_status' => $previous->value,
                        'new_status' => UserStatus::Active->value,
                    ],
                ), false);
                $this->entityManager->flush();

                return true;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::Conflict,
                'E-mail activation could not complete due to a lock conflict. Retry later.',
            );
        }

        if ($didMutate) {
            $this->authCache->invalidateUser($userId);
        }
    }

    /**
     * Best-effort login bookkeeping: failures must not invalidate the session.
     */
    public function recordSuccessfulLogin(User $user): void
    {
        try {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $user->recordLogin($now);
            $this->users->save($user, false);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::LoginSucceeded,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $user,
                subjectUser: $user,
                metadata: [
                    'source' => 'login_success',
                ],
            ), false);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Login audit/bookkeeping failed; authentication still succeeds.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}
