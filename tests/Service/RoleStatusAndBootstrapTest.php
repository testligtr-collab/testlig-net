<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InvalidUserTransitionException;
use App\Exception\SuperAdminBootstrapException;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\SecurityBootstrapGuardRepository;
use App\Repository\UserRepository;
use App\Service\EmailNormalizer;
use App\Service\PersonNameNormalizer;
use App\Service\SecurityAuditRecorder;
use App\Service\SuperAdminBootstrapService;
use App\Service\UserFactory;
use App\Service\UserGlobalRoleManager;
use App\Service\UserStatusManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RoleStatusAndBootstrapTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private SecurityAuditEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;

        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;

        $this->cleanup();
    }

    public function testRoleChangeWritesAudit(): void
    {
        $subject = $this->factory->createAndPersist('role-sub@example.com', 'Guclu-Parola-123!', 'S', 'U', UserRole::Student);
        $actor = $this->adminActor('role-act@example.com');
        $manager = static::getContainer()->get(UserGlobalRoleManager::class);
        self::assertInstanceOf(UserGlobalRoleManager::class, $manager);

        $manager->addRole($subject, UserRole::Teacher, $actor, 'promote teacher');

        self::assertContains(UserRole::Teacher->value, $subject->getRoles());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::RoleChanged->value));
    }

    public function testUnauthorizedActorCannotChangeRoles(): void
    {
        $subject = $this->factory->createAndPersist('role-unauth-sub@example.com', 'Guclu-Parola-123!', 'S', 'U', UserRole::Student);
        $actor = $this->factory->createAndPersist('role-unauth-act@example.com', 'Guclu-Parola-123!', 'A', 'C', UserRole::Student);
        $manager = static::getContainer()->get(UserGlobalRoleManager::class);
        self::assertInstanceOf(UserGlobalRoleManager::class, $manager);

        $this->expectException(InvalidUserTransitionException::class);
        $manager->addRole($subject, UserRole::Teacher, $actor, 'nope');
    }

    public function testStatusChangeWritesAudit(): void
    {
        $subject = $this->factory->createAndPersist('status-sub@example.com', 'Guclu-Parola-123!', 'S', 'U', UserRole::Student);
        $subject->markEmailVerified(new \DateTimeImmutable('now'));
        $subject->transitionTo(UserStatus::Active);
        $this->users->save($subject);
        $actor = $this->adminActor('status-act@example.com');
        $manager = static::getContainer()->get(UserStatusManager::class);
        self::assertInstanceOf(UserStatusManager::class, $manager);

        $manager->suspend($subject, $actor, 'abuse');
        self::assertSame(UserStatus::Suspended, $subject->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::StatusChanged->value));

        $manager->reactivate($subject, $actor, 'cleared');
        self::assertSame(UserStatus::Active, $subject->getStatus());
    }

    public function testArchivedCannotBeRandomlyReactivated(): void
    {
        $subject = $this->factory->createAndPersist('status-arch@example.com', 'Guclu-Parola-123!', 'S', 'U', UserRole::Student);
        $subject->transitionTo(UserStatus::Archived);
        $this->users->save($subject);
        $actor = $this->adminActor('status-arch-act@example.com');
        $manager = static::getContainer()->get(UserStatusManager::class);
        self::assertInstanceOf(UserStatusManager::class, $manager);

        $this->expectException(InvalidUserTransitionException::class);
        $manager->reactivate($subject, $actor, 'nope');
    }

    public function testBootstrapDisabledByDefault(): void
    {
        $service = static::getContainer()->get(SuperAdminBootstrapService::class);
        self::assertInstanceOf(SuperAdminBootstrapService::class, $service);
        $this->expectException(SuperAdminBootstrapException::class);
        $service->bootstrap('sa@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);
    }

    public function testBootstrapRequiresConfirm(): void
    {
        $service = $this->bootstrapService(true);
        $this->expectException(SuperAdminBootstrapException::class);
        $service->bootstrap('sa-confirm@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', false);
    }

    public function testBootstrapCreatesActiveVerifiedSuperAdminOnce(): void
    {
        $service = $this->bootstrapService(true);
        $user = $service->bootstrap('sa-first@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertNotNull($user->getEmailVerifiedAt());
        self::assertContains(UserRole::SuperAdmin->value, $user->getRoles());
        self::assertTrue($this->users->existsWithSuperAdminRole());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::SuperAdminBootstrapped->value));

        $this->expectException(SuperAdminBootstrapException::class);
        $service->bootstrap('sa-second@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);
    }

    public function testBootstrapRejectsExistingEmail(): void
    {
        $this->factory->createAndPersist('sa-taken@example.com', 'Guclu-Parola-123!', 'Ex', 'Ist', UserRole::Student);
        $service = $this->bootstrapService(true);

        $this->expectException(SuperAdminBootstrapException::class);
        $service->bootstrap('sa-taken@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);
    }

    public function testSequentialBootstrapOnlyOneSucceeds(): void
    {
        $service = $this->bootstrapService(true);
        $first = $service->bootstrap('sa-race-a@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);
        self::assertInstanceOf(User::class, $first);

        try {
            $service->bootstrap('sa-race-b@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);
            self::fail('Second bootstrap should fail');
        } catch (SuperAdminBootstrapException) {
        }

        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::SuperAdminBootstrapped->value));
        self::assertTrue($this->users->existsWithSuperAdminRole());
    }

    public function testBootstrapAuditFailureRollsBackUser(): void
    {
        $service = $this->bootstrapService(true);
        $user = $service->bootstrap('sa-ok@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);
        self::assertInstanceOf(User::class, $user);

        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM users');
        try {
            $service->bootstrap('sa-fail@example.com', 'Guclu-Parola-123!', 'Super', 'Admin', true);
            self::fail('Expected failure');
        } catch (SuperAdminBootstrapException) {
        }
        $after = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM users');
        self::assertSame($before, $after);
    }

    private function adminActor(string $email): User
    {
        $actor = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ad', 'Min', UserRole::Moderator);
        $actor->addGlobalRole(UserRole::Admin);
        $actor->markEmailVerified(new \DateTimeImmutable('now'));
        $actor->transitionTo(UserStatus::Active);
        $this->users->save($actor);

        return $actor;
    }

    private function bootstrapService(bool $allowed): SuperAdminBootstrapService
    {
        $c = static::getContainer();

        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $guards = $c->get(SecurityBootstrapGuardRepository::class);
        self::assertInstanceOf(SecurityBootstrapGuardRepository::class, $guards);
        $audit = $c->get(SecurityAuditRecorder::class);
        self::assertInstanceOf(SecurityAuditRecorder::class, $audit);
        $emailNormalizer = $c->get(EmailNormalizer::class);
        self::assertInstanceOf(EmailNormalizer::class, $emailNormalizer);
        $nameNormalizer = $c->get(PersonNameNormalizer::class);
        self::assertInstanceOf(PersonNameNormalizer::class, $nameNormalizer);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $validator = $c->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);
        $clock = $c->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new SuperAdminBootstrapService(
            $users,
            $guards,
            $audit,
            $emailNormalizer,
            $nameNormalizer,
            $hasher,
            $em,
            $validator,
            $clock,
            $allowed,
        );
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach (['security_audit_events', 'security_bootstrap_guards', 'reset_password_requests', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }
}
