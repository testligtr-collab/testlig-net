<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\EmailVerificationException;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Activates accounts after signed verify-email links are validated.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly UserRepository $users,
        private readonly UserAccountLifecycle $accountLifecycle,
    ) {
    }

    public function verifyFromRequest(Request $request, Uuid $userId): User
    {
        $user = $this->users->findOneById($userId);
        if (!$user instanceof User) {
            throw EmailVerificationException::invalid();
        }

        if (UserStatus::Active === $user->getStatus() && null !== $user->getEmailVerifiedAt()) {
            return $user;
        }

        if (UserStatus::PendingVerification !== $user->getStatus()) {
            throw EmailVerificationException::invalid();
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
                $request,
                $user->getId()->toRfc4122(),
                $user->getEmail(),
            );
        } catch (VerifyEmailExceptionInterface) {
            throw EmailVerificationException::invalid();
        }

        try {
            $this->accountLifecycle->markEmailVerifiedAndActivate($user);
        } catch (InvalidUserTransitionException) {
            throw EmailVerificationException::invalid();
        }

        return $user;
    }
}
