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
            'login-dest-teacher@example.com' => UserRole::Teacher,
            'login-dest-expert@example.com' => UserRole::ExpertTeacher,
            'login-dest-head@example.com' => UserRole::HeadTeacher,
            'login-dest-mod@example.com' => UserRole::Moderator,
        ] as $email => $role) {
            $client = static::createClient();
            $this->loginAs($client, $email, $role);
            self::assertResponseRedirects('/calisma-alani');
        }
    }

    public function testAdminAndSuperAdminLandOnYonetimEvenWithContentAccess(): void
    {
        foreach ([
            'login-dest-admin@example.com' => UserRole::Admin,
            'login-dest-super@example.com' => UserRole::SuperAdmin,
        ] as $email => $role) {
            $client = static::createClient();
            $this->loginAs($client, $email, $role);
            self::assertResponseRedirects('/yonetim');
        }
    }

    public function testStudentParentAndPlainAccountDestinations(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'login-dest-student@example.com', UserRole::Student);
        self::assertResponseRedirects('/ogrenci/kurulum');

        $this->completeStudent('login-dest-student-ready@example.com');
        $client = $this->newClient();
        $this->login($client, 'login-dest-student-ready@example.com');
        self::assertResponseRedirects('/ogrenci');

        $client = $this->newClient();
        $this->loginAs($client, 'login-dest-parent@example.com', UserRole::Parent);
        self::assertResponseRedirects('/veli');

        $client = $this->newClient();
        $this->loginAs($client, 'login-dest-plain@example.com', UserRole::User);
        self::assertResponseRedirects('/hesabim');
    }

    public function testPendingAccountCannotReachTheWorkspace(): void
    {
        $client = static::createClient();
        $this->createUser('login-dest-pending@example.com', UserRole::Teacher, false);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'login-dest-pending@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseRedirects('/giris');
    }

    public function testUnsafeTargetsFallBackAndSafeTargetsAreKept(): void
    {
        $client = static::createClient();
        $this->createUser('login-dest-target@example.com', UserRole::Teacher, true);
        $client->request('GET', '/giris');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', 'https://evil.example/phish');
        $session->save();
        $this->login($client, 'login-dest-target@example.com');
        self::assertResponseRedirects('/calisma-alani');

        $client = $this->newClient();
        $client->request('GET', '/giris');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', '//evil.example/phish');
        $session->save();
        $this->login($client, 'login-dest-target@example.com');
        self::assertResponseRedirects('/calisma-alani');

        $client = $this->newClient();
        $client->request('GET', '/yonetim');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', '/yonetim');
        $session->save();
        $this->login($client, 'login-dest-target@example.com');
        self::assertResponseRedirects('/calisma-alani');
        $client->followRedirect();
        $client->request('GET', '/yonetim');
        self::assertResponseStatusCodeSame(403);

        $client = $this->newClient();
        $client->request('GET', '/giris');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', '/giris');
        $session->save();
        $this->login($client, 'login-dest-target@example.com');
        self::assertResponseRedirects('/calisma-alani');

        $client = $this->newClient();
        $client->request('GET', '/giris');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', '/yonetim/icerikler');
        $session->save();
        $this->login($client, 'login-dest-target@example.com');
        self::assertResponseRedirects('/yonetim/icerikler');
    }

    public function testStudentCannotBeSentToTheWorkspaceOrAdminHome(): void
    {
        $client = static::createClient();
        $this->createUser('login-dest-student-block@example.com', UserRole::Student, true);
        $client->request('GET', '/giris');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', '/calisma-alani');
        $session->save();
        $this->login($client, 'login-dest-student-block@example.com');
        self::assertResponseRedirects('/ogrenci/kurulum');
        $client->followRedirect();
        $client->request('GET', '/calisma-alani');
        self::assertResponseStatusCodeSame(403);

        $client = $this->newClient();
        $this->loginAs($client, 'login-dest-parent-block@example.com', UserRole::Parent);
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
            if ($em->getConnection()->createSchemaManager()->tablesExist(['student_profiles'])) {
                $em->getConnection()->executeStatement('DELETE FROM student_profiles');
            }
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement("DELETE FROM users WHERE normalized_email LIKE 'login-dest-%@example.com'");
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
