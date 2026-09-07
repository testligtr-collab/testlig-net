<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;

/**
 * Controlled account status / verification mutations for application services.
 */
final class UserAccountLifecycle
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function markEmailVerifiedAndActivate(User $user): void
    {
        if (UserStatus::Active === $user->getStatus() && null !== $user->getEmailVerifiedAt()) {
            return;
        }

        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);
    }

    public function recordSuccessfulLogin(User $user): void
    {
        $user->recordLogin(new \DateTimeImmutable('now'));
        $this->users->save($user);
    }
}
