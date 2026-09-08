<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\PasswordChangeFailedException;
use App\Exception\PasswordResetFailedException;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
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
        private readonly ClockInterface $clock,
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
            $resetToken = $this->entityManager->wrapInTransaction(function () use ($user): ResetPasswordToken {
                $locked = $this->lockUser($user);
                if (!$locked instanceof User) {
                    throw PasswordResetFailedException::accountUnavailable();
                }
                if (UserStatus::Active !== $locked->getStatus()) {
                    throw PasswordResetFailedException::accountUnavailable();
                }

                $this->resetPasswordRequests->removeRequests($locked);

                return $this->resetPasswordHelper->generateResetToken($locked);
            });
        } catch (PasswordResetFailedException) {
            return;
        } catch (ResetPasswordExceptionInterface $exception) {
            $this->logger->notice('Password reset token generation skipped.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);

            return;
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Password reset request lock contention.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);

            return;
        }

        try {
            $this->passwordResetMailer->sendResetEmail($user, $resetToken);
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
        try {
            $this->entityManager->wrapInTransaction(function () use ($token, $plainPassword): void {
                $user = $this->validateTokenAndFetchActiveUser($token);
                $locked = $this->lockUser($user);
                if (!$locked instanceof User) {
                    throw PasswordResetFailedException::accountUnavailable();
                }

                // Re-validate after the row lock so a concurrent consumer cannot race.
                $revalidated = $this->validateTokenAndFetchActiveUser($token);
                if (!$revalidated->getId()->equals($locked->getId())) {
                    throw PasswordResetFailedException::invalidToken();
                }

                if (UserStatus::Active !== $locked->getStatus()) {
                    throw PasswordResetFailedException::accountUnavailable();
                }

                if ($this->passwordHasher->isPasswordValid($locked, $plainPassword)) {
                    throw PasswordResetFailedException::sameAsCurrent();
                }

                $hashed = $this->passwordHasher->hashPassword($locked, $plainPassword);
                $locked->setPassword($hashed, $this->nextPasswordChangedAt($locked));
                $this->users->save($locked, false);
                $this->resetPasswordRequests->removeRequests($locked);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Password reset lock contention.', [
                'exception_class' => $exception::class,
            ]);
            throw PasswordResetFailedException::conflict();
        }
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $currentPassword, $newPassword): void {
                $locked = $this->lockUser($user);
                if (!$locked instanceof User) {
                    throw PasswordChangeFailedException::accountUnavailable();
                }

                if (UserStatus::Active !== $locked->getStatus()) {
                    throw PasswordChangeFailedException::accountUnavailable();
                }

                if (!$this->passwordHasher->isPasswordValid($locked, $currentPassword)) {
                    throw PasswordChangeFailedException::invalidCurrentPassword();
                }

                if ($this->passwordHasher->isPasswordValid($locked, $newPassword)) {
                    throw PasswordChangeFailedException::sameAsCurrent();
                }

                $hashed = $this->passwordHasher->hashPassword($locked, $newPassword);
                $locked->setPassword($hashed, $this->nextPasswordChangedAt($locked));
                $this->resetPasswordRequests->removeRequests($locked);
                $this->users->save($locked, false);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Password change lock contention.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            throw PasswordChangeFailedException::conflict();
        }
    }

    public function getTokenLifetime(): int
    {
        return $this->resetPasswordHelper->getTokenLifetime();
    }

    public function generateFakeResetToken(): ResetPasswordToken
    {
        return $this->resetPasswordHelper->generateFakeResetToken();
    }

    private function lockUser(User $user): ?User
    {
        $locked = $this->entityManager->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);

        return $locked instanceof User ? $locked : null;
    }

    /**
     * MariaDB DATETIME is second-resolution; advance at least one second when needed.
     */
    private function nextPasswordChangedAt(User $user): \DateTimeImmutable
    {
        $nowSecond = self::toSecondPrecision(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $previousSecond = self::toSecondPrecision($user->getPasswordChangedAt());
        if ($nowSecond > $previousSecond) {
            return $nowSecond;
        }

        return $previousSecond->modify('+1 second');
    }

    private static function toSecondPrecision(\DateTimeImmutable $value): \DateTimeImmutable
    {
        $normalized = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), $value->getTimezone());

        return false !== $normalized ? $normalized : $value->setTime(
            (int) $value->format('H'),
            (int) $value->format('i'),
            (int) $value->format('s'),
        );
    }
}
