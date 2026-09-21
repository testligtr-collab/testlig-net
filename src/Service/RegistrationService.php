<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RegistrationRequest;
use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\DuplicateEmailException;
use App\Exception\RegistrationFailedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Public self-registration for student and parent account types only.
 *
 * Never assigns privileged, teacher, or institution-manager roles.
 */
final class RegistrationService
{
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly EmailVerificationSenderInterface $verificationMailer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(RegistrationRequest $request): User
    {
        $accountType = $request->accountType;
        $initialRole = $accountType->initialGlobalRole();

        try {
            $user = $this->entityManager->wrapInTransaction(function () use ($request, $accountType, $initialRole): User {
                $user = $this->userFactory->create(
                    email: $request->email,
                    plainPassword: $request->plainPassword,
                    firstName: $request->firstName,
                    lastName: $request->lastName,
                    initialRole: $initialRole,
                );
                $this->entityManager->persist($user);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::UserRegistered,
                    actorType: SecurityAuditActorType::System,
                    outcome: SecurityAuditOutcome::Success,
                    subjectUser: $user,
                    metadata: [
                        'source' => 'registration',
                        'account_type' => $accountType->value,
                        'new_roles' => $user->getRoles(),
                        'new_status' => $user->getStatus()->value,
                    ],
                ), false);
                $this->entityManager->flush();

                return $user;
            });
        } catch (DuplicateEmailException) {
            throw RegistrationFailedException::duplicateEmail();
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->notice('Registration unique constraint race on email.', [
                'exception_class' => $exception::class,
            ]);
            throw RegistrationFailedException::duplicateEmail();
        }

        try {
            $this->verificationMailer->sendVerificationEmail($user);
        } catch (TransportExceptionInterface $exception) {
            // Account remains pending_verification; user can use the resend flow.
            $this->logger->error('Verification email transport failed after registration.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
        }

        return $user;
    }
}
