<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\UserRepository;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\InstitutionWorkspaceGate;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InstitutionWorkspaceControllerTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    protected function setUp(): void
    {
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnonymousInstitutionRouteRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/kurum');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/kurum/siniflar');
        self::assertResponseRedirects('/giris');
    }

    public function testGlobalRolesWithoutMembershipAreDenied(): void
    {
        foreach ([
            UserRole::Student,
            UserRole::Parent,
            UserRole::Teacher,
            UserRole::Moderator,
            UserRole::InstitutionManager,
            UserRole::Admin,
            UserRole::SuperAdmin,
        ] as $role) {
            $email = strtolower($role->name).'-kurum@example.com';
            $this->createActive($email, $role);
            $client = static::createClient();
            $this->login($client, $email);
            $client->request('GET', '/kurum');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testPlainUserSeesOnboardingWithoutInstitutionData(): void
    {
        $this->createActive('plain-user@example.com', UserRole::User, 'Deniz', 'Kaya');
        $client = static::createClient();
        $this->login($client, 'plain-user@example.com');
        $client->request('GET', '/kurum');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kurum erişiminiz bulunmuyor', $html);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringNotContainsString('Aktif öğretmen', $html);
        self::assertStringContainsString('/basvuru/kurum', $html);
    }

    public function testOwnerSeesOwnInstitutionAndEmptyLists(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('bora-owner@example.com', UserRole::User, 'Bora', 'Demir');
        $this->createActive('secret-teacher@example.com', UserRole::User, 'Deniz', 'Kaya');
        $this->createActive('other-student@example.com', UserRole::Student, 'Ece', 'Ak');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->openInstitution('bora-owner@example.com', 'Bora Koleji');
        $this->addMember('ada-owner@example.com', 'secret-teacher@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');
        $this->addMember('bora-owner@example.com', 'other-student@example.com', InstitutionMembershipRole::Student, 'Bora Koleji');

        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        self::assertResponseRedirects('/kurum');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ada Koleji', $html);
        self::assertStringContainsString('Kurum sahibi', $html);
        self::assertStringNotContainsString('Bora Koleji', $html);
        self::assertStringNotContainsString('secret-teacher@example.com', $html);
        self::assertStringNotContainsString('other-student@example.com', $html);
        self::assertStringNotContainsString('ROLE_SUPER_ADMIN', $html);
        self::assertStringNotContainsString('Kurum değiştir', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorExists('a[aria-current="page"]');
        self::assertSelectorExists('button.nav-toggle[aria-controls="institution-mobile-nav"]');
        self::assertSelectorExists('#institution-mobile-nav[hidden]');
        self::assertSelectorExists('form[action="/cikis"] input[name="_csrf_token"]');
        self::assertStringContainsString('>1<', $html);
        self::assertStringContainsString('>0<', $html);

        $client->request('GET', '/kurum/siniflar');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz sınıf oluşturulmamış.', (string) $client->getResponse()->getContent());
        $client->request('GET', '/kurum/siniflar/aaaaaaaaaaaaaaaaaaaa');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/kurum/ogretmenler');
        self::assertResponseIsSuccessful();
        $teacherHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Deniz Kaya', $teacherHtml);
        self::assertStringNotContainsString('secret-teacher@example.com', $teacherHtml);
        self::assertStringNotContainsString('Ece Ak', $teacherHtml);
        $client->request('GET', '/kurum/ogrenciler');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz öğrenci yok.', (string) $client->getResponse()->getContent());
        $client->request('GET', '/kurum/testler');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz kurum testi yok.', (string) $client->getResponse()->getContent());
        $client->request('GET', '/kurum/testler/bbbbbbbbbbbbbbbbbbbb');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/kurum/baglam');
        self::assertResponseStatusCodeSame(405);
    }

    public function testManagerSeesPanelAndTeacherMembershipIsDenied(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('manager-user@example.com', UserRole::User, 'Mert', 'Sönmez');
        $this->createActive('staff-user@example.com', UserRole::User, 'Seda', 'Ak');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->addMember('ada-owner@example.com', 'manager-user@example.com', InstitutionMembershipRole::Manager, 'Ada Koleji');
        $this->addMember('ada-owner@example.com', 'staff-user@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');

        $client = static::createClient();
        $this->login($client, 'manager-user@example.com');
        $client->request('GET', '/kurum');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Yönetici', (string) $client->getResponse()->getContent());

        $staff = static::createClient();
        $this->login($staff, 'staff-user@example.com');
        $staff->request('GET', '/kurum');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuspendedInstitutionAndEndedMembershipAreDenied(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('pending-owner@example.com', UserRole::User, 'Pelin', 'Ak');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->createOnly('pending-owner@example.com', 'Bekleyen Koleji');
        $this->suspend('Ada Koleji');

        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $client->request('GET', '/kurum');
        self::assertResponseStatusCodeSame(403);

        $pending = static::createClient();
        $this->login($pending, 'pending-owner@example.com');
        $pending->request('GET', '/kurum');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInstitutionChoiceIsPostAndRevokedContextFallsAway(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('lead-user@example.com', UserRole::User, 'Mert', 'Sönmez');
        $this->createActive('secret-teacher@example.com', UserRole::User, 'Deniz', 'Kaya');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->openInstitution('lead-user@example.com', 'Bora Koleji');
        $this->addMember('ada-owner@example.com', 'lead-user@example.com', InstitutionMembershipRole::Manager, 'Ada Koleji');
        $this->addMember('ada-owner@example.com', 'secret-teacher@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');

        $client = static::createClient();
        $this->login($client, 'lead-user@example.com');
        $crawler = $client->request('GET', '/kurum');
        self::assertResponseIsSuccessful();
        $chooser = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kurum seçin', $chooser);
        self::assertStringContainsString('Ada Koleji', $chooser);
        self::assertStringContainsString('Bora Koleji', $chooser);
        self::assertStringNotContainsString('Aktif öğretmen', $chooser);
        self::assertGreaterThanOrEqual(2, $crawler->filter('form[action="/kurum/baglam"]')->count());

        $client->request('POST', '/kurum/baglam', ['reference' => 'aaaaaaaaaaaaaaaaaaaa']);
        self::assertResponseStatusCodeSame(403);

        $form = $crawler->filter('form[action="/kurum/baglam"]')->eq(0)->form();
        $client->submit($form);
        self::assertResponseRedirects('/kurum');
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ada Koleji', $html);
        self::assertMatchesRegularExpression('/Aktif öğretmen<\/h3>\s*<p>1<\/p>/', $html);
        self::assertMatchesRegularExpression('/Aktif öğrenci<\/h3>\s*<p>0<\/p>/', $html);

        $session = $client->getRequest()->getSession();
        $session->set(InstitutionWorkspaceGate::SESSION_KEY, '00000000-0000-7000-8000-000000000099');
        $session->save();
        $client->request('GET', '/kurum');
        self::assertResponseIsSuccessful();
        $invalid = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kurum seçin', $invalid);
        self::assertStringNotContainsString('Aktif öğretmen', $invalid);

        $this->endMembership('lead-user@example.com', 'Ada Koleji', 'ada-owner@example.com');
        $client->request('GET', '/kurum');
        self::assertResponseIsSuccessful();
        $after = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Bora Koleji', $after);
        self::assertStringNotContainsString('Ada Koleji', $after);
        self::assertStringNotContainsString('Deniz Kaya', $after);
    }

    private function openInstitution(string $ownerEmail, string $name): void
    {
        $this->createOnly($ownerEmail, $name);
        $this->withKernel(function () use ($name): void {
            $institution = $this->institution($name);
            $actor = $this->user('workspace-sa@example.com');
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->activate($institution, $actor, 'activate');
        });
    }

    private function createOnly(string $ownerEmail, string $name): void
    {
        $this->withKernel(function () use ($ownerEmail, $name): void {
            $creator = static::getContainer()->get(InstitutionCreator::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            $creator->create($this->user('workspace-sa@example.com'), $this->user($ownerEmail), $name, InstitutionType::School, 'setup');
        });
    }

    private function suspend(string $name): void
    {
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->suspend($this->institution($name), $this->user('workspace-sa@example.com'), 'suspend');
        });
    }

    private function addMember(string $actorEmail, string $subjectEmail, InstitutionMembershipRole $role, string $institutionName): void
    {
        $this->withKernel(function () use ($actorEmail, $subjectEmail, $role, $institutionName): void {
            $manager = static::getContainer()->get(InstitutionMembershipManager::class);
            self::assertInstanceOf(InstitutionMembershipManager::class, $manager);
            $manager->addMember(
                $this->institution($institutionName),
                $this->user($actorEmail),
                $this->user($subjectEmail),
                $role,
                'add_member',
            );
        });
    }

    private function endMembership(string $subjectEmail, string $institutionName, string $actorEmail): void
    {
        $this->withKernel(function () use ($subjectEmail, $institutionName, $actorEmail): void {
            $memberships = static::getContainer()->get(InstitutionMembershipRepository::class);
            $manager = static::getContainer()->get(InstitutionMembershipManager::class);
            self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
            self::assertInstanceOf(InstitutionMembershipManager::class, $manager);
            $membership = $memberships->findMembership($this->user($subjectEmail), $this->institution($institutionName));
            self::assertInstanceOf(InstitutionMembership::class, $membership);
            $manager->endMembership($membership, $this->user($actorEmail), 'end_member');
        });
    }

    private function institution(string $name): \App\Entity\Institution
    {
        $repo = static::getContainer()->get(\App\Repository\InstitutionRepository::class);
        self::assertInstanceOf(\App\Repository\InstitutionRepository::class, $repo);
        $institution = $repo->findOneBy(['name' => $name]);
        self::assertInstanceOf(\App\Entity\Institution::class, $institution);

        return $institution;
    }

    private function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function withKernel(callable $callback): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $callback();
        self::ensureKernelShutdown();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function createActive(string $email, UserRole $role, string $first = 'Ayşe', string $last = 'Yılmaz'): void
    {
        $this->withKernel(static function () use ($email, $role, $first, $last): void {
            $factory = static::getContainer()->get(UserFactory::class);
            $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
            $users = static::getContainer()->get(UserRepository::class);
            self::assertInstanceOf(UserFactory::class, $factory);
            self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
            self::assertInstanceOf(UserRepository::class, $users);
            $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
            $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
            if (UserStatus::PendingVerification === $user->getStatus()) {
                $lifecycle->markEmailVerifiedAndActivate($user);
            }
            if ($initial !== $role) {
                $user->addGlobalRole($role);
                $users->save($user);
            }
        });
    }

    private function purge(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            QuestionBankDbCleanup::deleteTables($em->getConnection(), [
                'institution_applications',
                'classroom_student_enrollments',
                'classroom_teacher_assignments',
                'classrooms',
                'academic_years',
                'institution_memberships',
                'institutions',
                'student_profiles',
                'security_audit_events',
                'users',
            ]);
            self::ensureKernelShutdown();
        } catch (\Throwable) {
        }
    }
}
