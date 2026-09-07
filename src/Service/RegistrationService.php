<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RegistrationRequest;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\DuplicateEmailException;
use App\Exception\RegistrationFailedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Public student self-registration. Always assigns ROLE_STUDENT server-side.
 */
final class RegistrationService
{
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly EmailVerificationMailer $verificationMailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(RegistrationRequest $request): User
    {
        try {
            $user = $this->userFactory->createAndPersist(
                email: $request->email,
                plainPassword: $request->plainPassword,
                firstName: $request->firstName,
                lastName: $request->lastName,
                initialRole: UserRole::Student,
            );
        } catch (DuplicateEmailException) {
            throw RegistrationFailedException::duplicateEmail();
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->notice('Registration unique constraint race on email.', [
                'exception_class' => $exception::class,
            ]);
            throw RegistrationFailedException::duplicateEmail();
        }

        $this->verificationMailer->sendVerificationEmail($user);

        return $user;
    }
}
