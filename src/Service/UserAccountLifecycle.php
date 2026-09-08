<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Controlled account status / verification mutations for application services.
 */
final class UserAccountLifecycle
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function markEmailVerifiedAndActivate(User $user): void
    {
        if (UserStatus::Active === $user->getStatus() && null !== $user->getEmailVerifiedAt()) {
            return;
        }

        $userId = $user->getId();
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $previous = $user->getStatus();
            $user->markEmailVerified($now);
            $user->transitionTo(UserStatus::Active);
            $this->users->save($user, false);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::EmailVerified,
                actorType: SecurityAuditActorType::System,
                outcome: SecurityAuditOutcome::Success,
                subjectUser: $user,
                metadata: [
                    'source' => 'email_verification',
                    'previous_status' => $previous->value,
                    'new_status' => UserStatus::Active->value,
                ],
            ), false);
            $this->entityManager->flush();
        });
        $this->authCache->invalidateUser($userId);
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
