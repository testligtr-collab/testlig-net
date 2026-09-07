<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Central gate: only active accounts may authenticate.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        match ($user->getStatus()) {
            UserStatus::Active => null,
            UserStatus::PendingVerification => throw new CustomUserMessageAccountStatusException(
                'Hesabınız henüz doğrulanmamış. Lütfen e-postanızdaki doğrulama bağlantısını kullanın.'
            ),
            UserStatus::Suspended => throw new CustomUserMessageAccountStatusException(
                'Hesabınız askıya alınmış. Destek ile iletişime geçin.'
            ),
            UserStatus::Archived => throw new CustomUserMessageAccountStatusException(
                'Bu hesap artık kullanılamıyor.'
            ),
        };
    }

    public function checkPostAuth(UserInterface $user): void
    {
        $this->checkPreAuth($user);
    }
}
