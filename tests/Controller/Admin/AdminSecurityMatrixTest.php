<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminShellMetrics;
use App\Service\ClassroomManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Security matrix for Stage 2.20 admin panel HTTP surface.
 */
final class AdminSecurityMatrixTest extends WebTestCase
{
    public function testAnonymousRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetim');
        self::assertResponseRedirects('/giris');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testYonetimPrefixDoesNotMatchYonetimFoo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetimfoo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStudentGetsForbidden(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_matrix_student@example.com', UserRole::Student);
        $client->request('GET', '/yonetim');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanOpenShellAndSystemButNotPayments(): void
    {
        $client = static::createClient();
        $admin = $this->loginAs($client, 'admin_matrix_admin@example.com', UserRole::Admin);

        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSelectorTextSame('h1', 'Genel Bakış');
        self::assertSelectorTextContains('body', 'Yönetim bölümleri');
        self::assertSelectorTextContains('body', 'Genel Bakış');
        self::assertStringNotContainsString('Sistem çalışıyor', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('.admin-search');
        self::assertSelectorNotExists('.admin-icon-btn');
        self::assertSelectorExists('a[href="/yonetim/haberler/yeni"]');
        self::assertCount(1, $client->getCrawler()->filter('a[href="/yonetim/haberler/yeni"]'));
        /** @var AdminShellMetrics $metrics */
        $metrics = static::getContainer()->get(AdminShellMetrics::class);
        foreach ($metrics->forActor($admin) as $card) {
            self::assertSelectorTextSame('.admin-metric--'.$card['tone'].' .admin-metric__value', (string) $card['value']);
        }
        self::assertSelectorTextContains('body', 'Kullanıcılar ve Yetkiler');
        self::assertSelectorTextContains('body', 'Aktif kullanıcı');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim"][aria-current="page"]');
        self::assertSelectorNotExists('#panel-sidebar a[href="/yonetim/sistem"][aria-current="page"]');
        self::assertSelectorExists('#admin-nav-shortcuts a[href="/yonetim/kullanicilar"]');
        self::assertSelectorNotExists('#admin-nav-shortcuts a[href="/yonetim/odemeler"]');
        self::assertSelectorExists('a[href="/yonetim/kullanicilar"]');
        self::assertSelectorNotExists('a[href="/yonetim/odemeler"]');
        self::assertSelectorNotExists('a[href="/yonetim/webhook"]');
        self::assertSelectorNotExists('a[href="/yonetim/uzlastirma"]');
        self::assertSelectorNotExists('a[href="/yonetim/denetim"]');
        self::assertSelectorExists('.panel-nav-group--learning a[href="/ogretmen/profil"]');
        self::assertSelectorNotExists('.panel-nav-group--learning a[href="/ogretmen/profil"][aria-current="page"]');
        self::assertSelectorNotExists('a[href="/ogretmen/siniflarim"]');
        self::assertSelectorNotExists('#nav-group-commerce');
        self::assertSelectorNotExists('nav.bottom-nav');
        self::assertSelectorNotExists('a[href="/yonetim/kurumlar/yeni"]');

        $client->request('GET', '/yonetim/sistem');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sistem durumu');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim/sistem"][aria-current="page"]');
        self::assertSelectorNotExists('#panel-sidebar a[href="/yonetim"][aria-current="page"]');

        $client->request('GET', '/yonetim/kullanicilar/'.$admin->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim/kullanicilar"][aria-current="page"]');
        self::assertSelectorNotExists('#panel-sidebar a[href="/yonetim"][aria-current="page"]');

        $client->request('GET', '/yonetim/odemeler');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/yonetim/webhook');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/yonetim/uzlastirma');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/yonetim/denetim');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminCanOpenPaymentAndAuditSurfaces(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_matrix_sa@example.com', UserRole::SuperAdmin);

        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSelectorTextContains('body', 'Genel Bakış');
        self::assertSelectorExists('a[href="/yonetim/kurumlar/yeni"]');
        self::assertSelectorExists('#nav-group-commerce');
        self::assertSelectorExists('#nav-group-system');
        self::assertSelectorExists('a[href="/yonetim/odemeler"]');
        self::assertSelectorExists('a[href="/yonetim/denetim"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma"]');
        self::assertSelectorExists('#admin-nav-shortcuts a[href="/yonetim/uzlastirma"]');
        self::assertSelectorTextContains('article[aria-labelledby="nav-shortcut-learning"]', 'Çalışıyor');
        self::assertSelectorTextContains('article[aria-labelledby="nav-shortcut-learning"]', 'Geliştirme aşamasında');
        self::assertStringNotContainsString('Tamamlandı', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('nav.bottom-nav');

        foreach ([
            '/yonetim/odemeler',
            '/yonetim/webhook',
            '/yonetim/uzlastirma',
            '/yonetim/denetim',
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful(\sprintf('%s should be allowed for SA', $path));
            self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
            self::assertResponseHeaderSame('Pragma', 'no-cache');
            self::assertSelectorExists(\sprintf('#panel-sidebar a[href="%s"][aria-current="page"]', $path));
            self::assertSelectorNotExists('#panel-sidebar a[href="/yonetim"][aria-current="page"]');
        }
    }

    public function testAdminWithoutTeacherRoleHidesTeacherLinks(): void
    {
        $admin = $this->createPrivilegedUser('admin_matrix_plain@example.com', UserRole::Admin);
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $stored = $users->findOneById($admin->getId());
        self::assertInstanceOf(User::class, $stored);
        $stored->setGlobalRoles([UserRole::Admin]);
        $users->save($stored);
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->submitLogin($client, 'admin_matrix_plain@example.com');
        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#nav-group-learning');
        self::assertSelectorNotExists('a[href="/ogretmen/profil"]');
        self::assertSelectorNotExists('a[href="/ogretmen/siniflarim"]');
    }

    public function testAdminTeacherProfileStaysInTheLearningGroup(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_matrix_teacher@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.panel-nav-group--learning a[href="/ogretmen/profil"]');
        self::assertSelectorNotExists('.panel-nav-group--learning a[href="/ogretmen/profil"][aria-current="page"]');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim"][aria-current="page"]');
        self::assertSelectorNotExists('a[href="/ogretmen/siniflarim"]');

        $client->request('GET', '/ogretmen/profil');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/ogretmen/profil"][aria-current="page"]');
        $current = $this->currentNavIds('admin_matrix_teacher@example.com', '/ogretmen/profil');
        self::assertTrue($current['teacher_profile'] ?? false);
        self::assertArrayNotHasKey('classrooms', $current);
        self::assertArrayNotHasKey('dashboard', $current);
    }

    public function testAssignedAdminKeepsClassroomsInTheLearningGroup(): void
    {
        $this->createPrivilegedUser('admin_matrix_nav_sa@example.com', UserRole::SuperAdmin);
        $this->createPrivilegedUser('admin_matrix_nav_owner@example.com', UserRole::User);
        $this->createPrivilegedUser('admin_matrix_nav_admin@example.com', UserRole::Admin);
        $this->assignAdminToClassroom();

        $client = static::createClient();
        $this->submitLogin($client, 'admin_matrix_nav_admin@example.com');
        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.panel-nav-group--campus a[href="/ogretmen/siniflarim"]');
        self::assertSelectorExists('.panel-nav-group--learning a[href="/ogretmen/profil"]');
        self::assertSelectorNotExists('.panel-nav-group--campus a[href="/ogretmen/siniflarim"][aria-current="page"]');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim"][aria-current="page"]');

        $client->request('GET', '/ogretmen/siniflarim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/ogretmen/siniflarim"][aria-current="page"]');
        $current = $this->currentNavIds('admin_matrix_nav_admin@example.com', '/ogretmen/siniflarim');
        self::assertTrue($current['classrooms'] ?? false);
        self::assertArrayNotHasKey('teacher_profile', $current);
    }

    public function testStaleSuperAdminDenied(): void
    {
        $client = static::createClient();
        $user = $this->loginAs($client, 'admin_matrix_stale_sa@example.com', UserRole::SuperAdmin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownPaymentAttemptReturnsNotFound(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_matrix_nf_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/odemeler/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownWebhookAndReconciliationReturnNotFound(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_matrix_nf2_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/webhook/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/yonetim/uzlastirma/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidPaymentFilterReturnsNotFound(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_matrix_filter_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/odemeler', ['status' => 'not_a_status']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPageSizeIsBounded(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_matrix_page_sa@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim/odemeler', ['page_size' => 1000]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', '100/sayfa');
        self::assertSelectorNotExists('a[href*="page_size=1000"]');
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): User
    {
        $user = $this->createPrivilegedUser($email, $role);
        $this->submitLogin($client, $email);

        return $user;
    }

    private function submitLogin(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    /**
     * @return array<string, bool>
     */
    private function currentNavIds(string $email, string $path): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $builder = static::getContainer()->get(AdminNavBuilder::class);
        $users = static::getContainer()->get(UserRepository::class);
        $stack = static::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(AdminNavBuilder::class, $builder);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(RequestStack::class, $stack);
        $actor = $users->findOneByNormalizedEmail($email);
        self::assertInstanceOf(User::class, $actor);
        $stack->push(Request::create($path));
        try {
            $sections = $builder->build($actor)['nav_sections'];
        } finally {
            $stack->pop();
        }
        $current = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if ($item['current']) {
                    $current[$item['id']] = true;
                }
            }
        }

        return $current;
    }

    private function assignAdminToClassroom(): void
    {
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        $institutions = static::getContainer()->get(InstitutionRepository::class);
        $memberships = static::getContainer()->get(InstitutionMembershipRepository::class);
        $creator = static::getContainer()->get(InstitutionCreator::class);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        $members = static::getContainer()->get(InstitutionMembershipManager::class);
        $years = static::getContainer()->get(AcademicYearManager::class);
        $classrooms = static::getContainer()->get(ClassroomManager::class);
        $assignments = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(InstitutionRepository::class, $institutions);
        self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        self::assertInstanceOf(InstitutionMembershipManager::class, $members);
        self::assertInstanceOf(AcademicYearManager::class, $years);
        self::assertInstanceOf(ClassroomManager::class, $classrooms);
        self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $assignments);

        $superAdmin = $users->findOneByNormalizedEmail('admin_matrix_nav_sa@example.com');
        $owner = $users->findOneByNormalizedEmail('admin_matrix_nav_owner@example.com');
        $admin = $users->findOneByNormalizedEmail('admin_matrix_nav_admin@example.com');
        self::assertInstanceOf(User::class, $superAdmin);
        self::assertInstanceOf(User::class, $owner);
        self::assertInstanceOf(User::class, $admin);

        $creator->create($superAdmin, $owner, 'Nav Menu Okulu', InstitutionType::School, 'nav_seed');
        $institution = $institutions->findOneBy(['name' => 'Nav Menu Okulu']);
        self::assertInstanceOf(Institution::class, $institution);
        $status->activate($institution, $superAdmin, 'nav_seed');
        $members->addMember($institution, $superAdmin, $admin, InstitutionMembershipRole::Teacher, 'nav_seed');
        $year = $years->createPlanned(
            $institution,
            $superAdmin,
            'Nav Yili',
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2027-06-15'),
            'nav_seed',
        );
        $classroom = $classrooms->create($year, $superAdmin, 'Nav Sinifi', GradeLevel::Grade3, 'nav_seed', 'A', 20);
        $membership = $memberships->findMembership($admin, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        $assignments->assign($classroom, $superAdmin, $membership, TeacherAssignmentRole::AssistantTeacher, 'nav_seed');
        self::ensureKernelShutdown();
    }

    private function createPrivilegedUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ad', 'Min', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        self::ensureKernelShutdown();

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'admin_matrix_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
