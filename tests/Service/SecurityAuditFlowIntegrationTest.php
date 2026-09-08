<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\RegistrationRequest;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\PasswordManager;
use App\Service\RegistrationService;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class SecurityAuditFlowIntegrationTest extends KernelTestCase
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

    public function testRegisterCreatesAudit(): void
    {
        $registration = static::getContainer()->get(RegistrationService::class);
        self::assertInstanceOf(RegistrationService::class, $registration);
        $request = new RegistrationRequest();
        $request->email = 'reg-audit@example.com';
        $request->plainPassword = 'Guclu-Parola-123!';
        $request->firstName = 'Reg';
        $request->lastName = 'User';
        $request->agreeTerms = true;

        $registration->register($request);
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::UserRegistered->value));
    }

    public function testEmailVerifyCreatesAudit(): void
    {
        $user = $this->factory->createAndPersist('verify-audit@example.com', 'Guclu-Parola-123!', 'V', 'U', UserRole::Student);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $lifecycle->markEmailVerifiedAndActivate($user);

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::EmailVerified->value));
    }

    public function testLoginSuccessCreatesAudit(): void
    {
        $user = $this->createActive('login-audit@example.com');
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $lifecycle->recordSuccessfulLogin($user);

        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::LoginSucceeded->value));
    }

    public function testLoginAuditFailureDoesNotBreakLoginBookkeepingCatch(): void
    {
        $user = $this->createActive('login-best-effort@example.com');
        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');

        try {
            $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
            self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
            // Must not throw even when audit persistence fails.
            $lifecycle->recordSuccessfulLogin($user);
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
        }

        self::assertSame('login-best-effort@example.com', $user->getNormalizedEmail());
    }

    public function testPasswordChangeAuditInSameTransaction(): void
    {
        $user = $this->createActive('chg-audit@example.com');
        $passwords = static::getContainer()->get(PasswordManager::class);
        self::assertInstanceOf(PasswordManager::class, $passwords);
        $passwords->changePassword($user, 'Guclu-Parola-123!', 'Yeni-Guclu-Parola-456!');

        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::PasswordChanged->value));
    }

    public function testPasswordResetAuditInSameTransaction(): void
    {
        $user = $this->createActive('rst-audit@example.com');
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelperInterface::class, $helper);
        $token = $helper->generateResetToken($user);
        $passwords = static::getContainer()->get(PasswordManager::class);
        self::assertInstanceOf(PasswordManager::class, $passwords);
        $passwords->resetPassword($token->getToken(), 'Yeni-Guclu-Parola-789!');

        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::PasswordResetCompleted->value));
    }

    public function testPasswordChangeRollsBackWhenAuditTableMissing(): void
    {
        $user = $this->createActive('chg-rollback@example.com');
        $oldHash = $user->getPassword();
        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');

        try {
            $passwords = static::getContainer()->get(PasswordManager::class);
            self::assertInstanceOf(PasswordManager::class, $passwords);
            try {
                $passwords->changePassword($user, 'Guclu-Parola-123!', 'Yeni-Guclu-Parola-999!');
                self::fail('Expected password change to fail when audit cannot persist');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $this->em = $em;
            $users = static::getContainer()->get(UserRepository::class);
            self::assertInstanceOf(UserRepository::class, $users);
            $this->users = $users;
        }

        $reloaded = $this->users->findOneByNormalizedEmail('chg-rollback@example.com');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($oldHash, $reloaded->getPassword());
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        self::assertSame(0, $events->countByAction(SecurityAuditAction::PasswordChanged->value));
    }

    private function createActive(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['security_audit_events_bak']) && !$schema->tablesExist(['security_audit_events'])) {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
        }
        foreach (['security_audit_events', 'security_bootstrap_guards', 'reset_password_requests', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
