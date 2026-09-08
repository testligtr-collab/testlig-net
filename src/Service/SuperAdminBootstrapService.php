<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\SecurityBootstrapGuard;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\SuperAdminBootstrapException;
use App\Repository\SecurityBootstrapGuardRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * One-shot ROLE_SUPER_ADMIN bootstrap. Not reachable through UserFactory / UserGlobalRoleManager.
 */
final class SuperAdminBootstrapService
{
    private const LOCK_NAME = 'testlig_super_admin_bootstrap';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityBootstrapGuardRepository $guards,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly PersonNameNormalizer $nameNormalizer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
        #[Autowire('%env(bool:ALLOW_SUPER_ADMIN_BOOTSTRAP)%')]
        private readonly bool $allowBootstrap,
    ) {
    }

    public function bootstrap(
        string $email,
        string $plainPassword,
        string $firstName,
        string $lastName,
        bool $confirmed,
    ): User {
        if (!$this->allowBootstrap) {
            throw SuperAdminBootstrapException::disabled();
        }
        if (!$confirmed) {
            throw SuperAdminBootstrapException::confirmationRequired();
        }

        $this->assertStrongPassword($plainPassword);

        $emails = $this->emailNormalizer->normalizePair($email);
        $firstName = $this->nameNormalizer->normalize($firstName, 'firstName');
        $lastName = $this->nameNormalizer->normalize($lastName, 'lastName');

        $connection = $this->entityManager->getConnection();
        $lock = (int) $connection->fetchOne('SELECT GET_LOCK(?, 10)', [self::LOCK_NAME]);
        if (1 !== $lock) {
            throw SuperAdminBootstrapException::lockBusy();
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($emails, $plainPassword, $firstName, $lastName): User {
                if ($this->users->existsWithSuperAdminRole() || null !== $this->guards->findSuperAdminGuard()) {
                    throw SuperAdminBootstrapException::alreadyExists();
                }

                if ($this->users->existsWithNormalizedEmail($emails['normalizedEmail'])) {
                    throw SuperAdminBootstrapException::emailTaken();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $user = User::create(
                    email: $emails['email'],
                    normalizedEmail: $emails['normalizedEmail'],
                    firstName: $firstName,
                    lastName: $lastName,
                    passwordHash: '!',
                    initialRole: UserRole::SuperAdmin,
                );
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword), $now);
                $user->markEmailVerified($now);
                $user->transitionTo(UserStatus::Active);

                $this->users->save($user, false);

                try {
                    $this->guards->save(SecurityBootstrapGuard::forSuperAdmin($user, $now), false);
                } catch (UniqueConstraintViolationException) {
                    throw SuperAdminBootstrapException::alreadyExists();
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::SuperAdminBootstrapped,
                    actorType: SecurityAuditActorType::Cli,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $user,
                    subjectUser: $user,
                    metadata: [
                        'reason' => 'one_shot_cli_bootstrap',
                        'source' => 'app:user:bootstrap-super-admin',
                        'bootstrap' => true,
                        'new_roles' => $user->getRoles(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $user;
            });
        } finally {
            $connection->executeStatement('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }

    private function assertStrongPassword(string $plainPassword): void
    {
        $violations = $this->validator->validate($plainPassword, [
            new Assert\NotBlank(message: 'Password is required.'),
            new Assert\Length(
                min: 12,
                max: 4096,
                minMessage: 'Password must be at least {{ limit }} characters.',
                maxMessage: 'Password is too long.',
            ),
            new Assert\PasswordStrength(
                minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
                message: 'Password is not strong enough.',
            ),
        ]);

        if (\count($violations) > 0) {
            throw SuperAdminBootstrapException::weakPassword((string) $violations->get(0)->getMessage());
        }
    }
}
