<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Central gate: only active accounts may authenticate.
 *
 * User-facing failures share one generic message to avoid account enumeration.
 */
final class UserChecker implements UserCheckerInterface
{
    private const GENERIC_LOGIN_FAILURE = 'Giriş bilgileri hatalı veya hesap şu anda kullanılamıyor.';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (UserStatus::Active === $user->getStatus()) {
            return;
        }

        $this->logger->info('Login blocked by account status.', [
            'user_id' => $user->getId()->toRfc4122(),
            'status' => $user->getStatus()->value,
        ]);

        throw new CustomUserMessageAccountStatusException(self::GENERIC_LOGIN_FAILURE);
    }

    public function checkPostAuth(UserInterface $user): void
    {
        $this->checkPreAuth($user);
    }
}
