<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\PasswordChangeFailedException;
use App\Exception\PasswordResetFailedException;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Password reset and change flows. Controllers must not mutate User passwords directly.
 */
final class PasswordManager
{
    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly ResetPasswordRequestRepository $resetPasswordRequests,
        private readonly UserRepository $users,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly PasswordResetNotifierInterface $passwordResetMailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Always succeeds from the caller's perspective (enumeration-safe).
     * Sends mail only for active accounts.
     */
    public function requestReset(string $email): void
    {
        try {
            $normalized = $this->emailNormalizer->normalize($email);
        } catch (\InvalidArgumentException) {
            return;
        }

        $user = $this->users->findOneByNormalizedEmail($normalized);
        if (!$user instanceof User) {
            return;
        }

        if (UserStatus::Active !== $user->getStatus()) {
            return;
        }

        try {
            // Replace any prior active request for this user.
            $this->resetPasswordRequests->removeRequests($user);
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
            $this->passwordResetMailer->sendResetEmail($user, $resetToken);
        } catch (ResetPasswordExceptionInterface $exception) {
            $this->logger->notice('Password reset token generation skipped.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Password reset email transport failed.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function validateTokenAndFetchActiveUser(string $token): User
    {
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            throw PasswordResetFailedException::invalidToken();
        }

        if (!$user instanceof User) {
            throw PasswordResetFailedException::invalidToken();
        }

        if (UserStatus::Active !== $user->getStatus()) {
            throw PasswordResetFailedException::accountUnavailable();
        }

        return $user;
    }

    public function resetPassword(string $token, string $plainPassword): void
    {
        $user = $this->validateTokenAndFetchActiveUser($token);

        if ($this->passwordHasher->isPasswordValid($user, $plainPassword)) {
            throw PasswordResetFailedException::sameAsCurrent();
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $plainPassword): void {
            // Re-check status inside the transaction boundary.
            $this->entityManager->refresh($user);
            if (UserStatus::Active !== $user->getStatus()) {
                throw PasswordResetFailedException::accountUnavailable();
            }

            $hashed = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashed);
            $this->users->save($user);
            $this->resetPasswordRequests->removeRequests($user);
        });
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (UserStatus::Active !== $user->getStatus()) {
            throw PasswordChangeFailedException::accountUnavailable();
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw PasswordChangeFailedException::invalidCurrentPassword();
        }

        if ($this->passwordHasher->isPasswordValid($user, $newPassword)) {
            throw PasswordChangeFailedException::sameAsCurrent();
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $newPassword): void {
            $this->entityManager->refresh($user);
            if (UserStatus::Active !== $user->getStatus()) {
                throw PasswordChangeFailedException::accountUnavailable();
            }

            $hashed = $this->passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashed);
            $this->resetPasswordRequests->removeRequests($user);
            $this->users->save($user);
        });
    }

    public function getTokenLifetime(): int
    {
        return $this->resetPasswordHelper->getTokenLifetime();
    }

    public function generateFakeResetToken(): ResetPasswordToken
    {
        return $this->resetPasswordHelper->generateFakeResetToken();
    }
}
