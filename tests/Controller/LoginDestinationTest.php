<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\StudentProfileManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginDestinationTest extends WebTestCase
{
    public function testAnonymousWorkspaceVisitOpensLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/calisma-alani');
        self::assertResponseRedirects('/giris');
    }

    public function testContentRolesLandOnTheWorkspace(): void
    {
        foreach ([
            'logindest-teacher@example.com' => UserRole::Teacher,
            'logindest-expert@example.com' => UserRole::ExpertTeacher,
            'logindest-head@example.com' => UserRole::HeadTeacher,
            'logindest-mod@example.com' => UserRole::Moderator,
        ] as $email => $role) {
            $client = static::createClient();
            $this->loginAs($client, $email, $role);
            self::assertResponseRedirects('/calisma-alani');
        }
    }

    public function testAdminAndSuperAdminLandOnYonetimEvenWithContentAccess(): void
    {
        foreach ([
            'logindest-admin@example.com' => UserRole::Admin,
            'logindest-super@example.com' => UserRole::SuperAdmin,
        ] as $email => $role) {
            $client = static::createClient();
            $this->loginAs($client, $email, $role);
            self::assertResponseRedirects('/yonetim');
        }
    }

    public function testStudentParentAndPlainAccountDestinations(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'logindest-student@example.com', UserRole::Student);
        self::assertResponseRedirects('/ogrenci/kurulum');

        $this->completeStudent('logindest-student-ready@example.com');
        $client = $this->newClient();
        $this->login($client, 'logindest-student-ready@example.com');
        self::assertResponseRedirects('/ogrenci');

        $client = $this->newClient();
        $this->loginAs($client, 'logindest-parent@example.com', UserRole::Parent);
        self::assertResponseRedirects('/veli');

        $client = $this->newClient();
        $this->loginAs($client, 'logindest-plain@example.com', UserRole::User);
        self::assertResponseRedirects('/hesabim');
    }

    public function testStoredTargetsStayInsideWhatTheUserCanOpen(): void
    {
        $this->createUser('logindest-target@example.com', UserRole::Teacher, true);

        $client = $this->newClient();
        $client->request('GET', '/yonetim');
        self::assertResponseRedirects('/giris');
        $this->login($client, 'logindest-target@example.com');
        self::assertResponseRedirects('/calisma-alani');
        $client->followRedirect();
        $client->request('GET', '/yonetim');
        self::assertResponseStatusCodeSame(403);

        $client = $this->newClient();
        $client->request('GET', '/yonetim/icerikler');
        self::assertResponseRedirects('/giris');
        $this->login($client, 'logindest-target@example.com');
        self::assertResponseRedirects('/yonetim/icerikler');
    }

    public function testStudentCannotBeSentToTheWorkspaceOrAdminHome(): void
    {
        $this->createUser('logindest-student-block@example.com', UserRole::Student, true);
        $client = static::createClient();
        $client->request('GET', '/calisma-alani');
        self::assertResponseRedirects('/giris');
        $this->login($client, 'logindest-student-block@example.com');
        self::assertResponseRedirects('/ogrenci/kurulum');
        $client->followRedirect();
        $client->request('GET', '/calisma-alani');
        self::assertResponseStatusCodeSame(403);

        $client = $this->newClient();
        $this->loginAs($client, 'logindest-parent-block@example.com', UserRole::Parent);
        self::assertResponseRedirects('/veli');
        $client->followRedirect();
        $client->request('GET', '/calisma-alani');
        self::assertResponseStatusCodeSame(403);
    }

    private function completeStudent(string $email): void
    {
        $user = $this->createUser($email, UserRole::Student, true);
        self::ensureKernelShutdown();
        self::bootKernel();
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        self::assertInstanceOf(StudentProfileManager::class, $profiles);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $fresh = $users->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        $profile = new StudentProfileRequest();
        $profile->gradeLevel = GradeLevel::Grade1;
        $profiles->completeOnboarding($fresh, $profile);
        self::ensureKernelShutdown();
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): void
    {
        $this->createUser($email, $role, true);
        $this->login($client, $email);
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
    }

    private function newClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function createUser(string $email, UserRole $role, bool $active): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ad', 'Min', $initial);
        if ($active) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
            $user->transitionTo(UserStatus::Active);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);
        self::ensureKernelShutdown();

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['student_profiles', 'users'])) {
                $em->getConnection()->executeStatement("DELETE FROM student_profiles WHERE user_id IN (SELECT id FROM users WHERE normalized_email LIKE 'logindest-%@example.com')");
            }
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement("DELETE FROM users WHERE normalized_email LIKE 'logindest-%@example.com'");
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
