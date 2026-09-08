<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\DuplicateEmailException;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use App\Service\UserGlobalRoleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\UuidV7;

final class UserFactoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserPasswordHasherInterface $hasher;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $factory = $container->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;

        $hasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $this->hasher = $hasher;

        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    public function testCreatesUuidV7PendingUserWithRoleUser(): void
    {
        $user = $this->factory->create(
            'Student@Example.com',
            'Plain-Password-123!',
            'Ayşe',
            'Yılmaz',
            UserRole::Student,
        );

        self::assertInstanceOf(UuidV7::class, $user->getId());
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        self::assertContains(UserRole::User->value, $user->getRoles());
        self::assertContains(UserRole::Student->value, $user->getRoles());
        self::assertSame('Student@Example.com', $user->getEmail());
        self::assertSame('student@example.com', $user->getNormalizedEmail());
        self::assertSame('student@example.com', $user->getUserIdentifier());
    }

    public function testInitialRoleIsAssignedWithoutDuplicates(): void
    {
        $user = $this->factory->create(
            'teacher@example.com',
            'Plain-Password-123!',
            'Ali',
            'Demir',
            UserRole::Teacher,
        );
        $user->addGlobalRole(UserRole::Teacher);

        self::assertSame([UserRole::Teacher->value, UserRole::User->value], $user->getRoles());
    }

    public function testAdminAndSuperAdminCannotBeBootstrapRoles(): void
    {
        $this->expectException(InvalidUserTransitionException::class);
        $this->factory->create('admin@example.com', 'Plain-Password-123!', 'Ada', 'Admin', UserRole::Admin);
    }

    public function testSuperAdminCannotBeBootstrapRole(): void
    {
        $this->expectException(InvalidUserTransitionException::class);
        $this->factory->create('root@example.com', 'Plain-Password-123!', 'Root', 'User', UserRole::SuperAdmin);
    }

    public function testPasswordIsHashedNotPlainText(): void
    {
        $plain = 'Plain-Password-123!';
        $user = $this->factory->create('hash@example.com', $plain, 'Hash', 'User', UserRole::User);

        self::assertNotSame($plain, $user->getPassword());
        self::assertTrue($this->hasher->isPasswordValid($user, $plain));
        self::assertStringNotContainsString($plain, (string) $user);
    }

    public function testPasswordIsNotInSerializerOutput(): void
    {
        $user = $this->factory->create('ser@example.com', 'Plain-Password-123!', 'Ser', 'User', UserRole::User);
        /** @var SerializerInterface $serializer */
        $serializer = static::getContainer()->get(SerializerInterface::class);
        $json = $serializer->serialize($user, 'json');

        self::assertStringNotContainsString('Plain-Password-123!', $json);
        self::assertStringNotContainsString($user->getPassword(), $json);
        self::assertStringNotContainsString('"password"', $json);
    }

    public function testDuplicateEmailCaseInsensitiveIsRejected(): void
    {
        $this->factory->createAndPersist('dup@example.com', 'Plain-Password-123!', 'One', 'User', UserRole::Student);

        $this->expectException(DuplicateEmailException::class);
        $this->factory->createAndPersist('DUP@example.com', 'Plain-Password-123!', 'Two', 'User', UserRole::Student);
    }

    public function testRepositoryFindsByNormalizedEmail(): void
    {
        $this->factory->createAndPersist('Find.Me@Example.com', 'Plain-Password-123!', 'Find', 'Me', UserRole::Parent);

        $found = $this->users->findOneByNormalizedEmail('find.me@example.com');
        self::assertInstanceOf(User::class, $found);
        self::assertTrue($this->users->existsWithNormalizedEmail('find.me@example.com'));
    }

    public function testStatusTransitionsAreControlled(): void
    {
        $user = $this->factory->create('status@example.com', 'Plain-Password-123!', 'Status', 'User', UserRole::User);
        $user->transitionTo(UserStatus::Active);
        self::assertSame(UserStatus::Active, $user->getStatus());

        $this->expectException(InvalidUserTransitionException::class);
        $user->transitionTo(UserStatus::PendingVerification);
    }

    public function testGlobalRoleManagerBlocksSuperAdminViaReplaceRoles(): void
    {
        $user = $this->factory->createAndPersist('roles@example.com', 'Plain-Password-123!', 'Role', 'User', UserRole::Moderator);
        $actor = $this->createAdminActor('roles-actor@example.com');
        /** @var UserGlobalRoleManager $manager */
        $manager = static::getContainer()->get(UserGlobalRoleManager::class);

        $this->expectException(InvalidUserTransitionException::class);
        $manager->replaceRoles($user, [UserRole::SuperAdmin], $actor, 'test');
    }

    public function testGlobalRoleManagerBlocksSuperAdminViaAddRole(): void
    {
        $user = $this->factory->createAndPersist('roles-add@example.com', 'Plain-Password-123!', 'Role', 'User', UserRole::Moderator);
        $actor = $this->createAdminActor('roles-add-actor@example.com');
        /** @var UserGlobalRoleManager $manager */
        $manager = static::getContainer()->get(UserGlobalRoleManager::class);

        $this->expectException(InvalidUserTransitionException::class);
        $manager->addRole($user, UserRole::SuperAdmin, $actor, 'test');
    }

    public function testNoApplicationServicePathAssignsSuperAdmin(): void
    {
        $user = $this->factory->createAndPersist('no-sa@example.com', 'Plain-Password-123!', 'No', 'Sa', UserRole::Teacher);
        $actor = $this->createAdminActor('no-sa-actor@example.com');
        /** @var UserGlobalRoleManager $manager */
        $manager = static::getContainer()->get(UserGlobalRoleManager::class);

        try {
            $manager->addRole($user, UserRole::SuperAdmin, $actor, 'test');
            self::fail('Expected SuperAdmin addRole to be rejected.');
        } catch (InvalidUserTransitionException) {
        }

        try {
            $manager->replaceRoles($user, [UserRole::Admin, UserRole::SuperAdmin], $actor, 'test');
            self::fail('Expected SuperAdmin replaceRoles to be rejected.');
        } catch (InvalidUserTransitionException) {
        }

        self::assertNotContains(UserRole::SuperAdmin->value, $user->getRoles());
    }

    private function createAdminActor(string $email): User
    {
        $actor = $this->factory->createAndPersist($email, 'Plain-Password-123!', 'Admin', 'Actor', UserRole::Moderator);
        $actor->addGlobalRole(UserRole::Admin);
        $actor->markEmailVerified(new \DateTimeImmutable('now'));
        $actor->transitionTo(UserStatus::Active);
        $this->users->save($actor);

        return $actor;
    }

    public function testTurkishNamesPreservedOnPersist(): void
    {
        $user = $this->factory->createAndPersist(
            'turkce@example.com',
            'Plain-Password-123!',
            '  Çiğdem  ',
            '  Şölen  ',
            UserRole::Teacher,
        );

        $this->em->clear();
        $reloaded = $this->users->findOneByNormalizedEmail('turkce@example.com');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('Çiğdem', $reloaded->getFirstName());
        self::assertSame('Şölen', $reloaded->getLastName());
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        foreach (['security_audit_events', 'security_bootstrap_guards', 'reset_password_requests', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        parent::tearDown();
    }
}
