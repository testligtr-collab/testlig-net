<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Dto\AdminUserRolesRequest;
use App\Dto\AdminUserStatusRequest;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\InstitutionCreator;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Security matrix for Stage 2.21 admin identity (users + institutions).
 *
 * Production-only preview surfaces are not asserted here; no dedicated preview test exists in this suite yet.
 */
final class AdminIdentityInstitutionSecurityMatrixTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testAnonymousUsersAndInstitutionsRedirectToLogin(): void
    {
        $client = static::createClient();
        foreach (['/yonetim/kullanicilar', '/yonetim/kurumlar'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/giris', 302, $path);
        }
    }

    public function testStudentAndModeratorForbiddenOnUsersAndInstitutions(): void
    {
        $client = static::createClient();
        foreach ([UserRole::Student, UserRole::Moderator] as $role) {
            $email = \sprintf('admin_id_matrix_%s@example.com', strtolower($role->name));
            $this->loginAs($client, $email, $role);
            foreach (['/yonetim/kullanicilar', '/yonetim/kurumlar'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(403, \sprintf('%s for %s', $path, $role->value));
            }
            $client->restart();
        }
    }

    public function testAdminCanOpenUsersInstitutionsDetailAndNav(): void
    {
        $client = static::createClient();
        $target = $this->createTeacherUser('admin_id_matrix_teacher_view@example.com');
        $this->loginAs($client, 'admin_id_matrix_admin@example.com', UserRole::Admin);

        $client->request('GET', '/yonetim/kullanicilar');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/yonetim/kullanicilar"]');
        self::assertSelectorExists('a[href="/yonetim/kurumlar"]');

        $client->request('GET', '/yonetim/kurumlar');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/yonetim/kullanicilar/'.$target->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $target->getEmail());
    }

    public function testAdminCannotOpenNewInstitutionOrMembersPath(): void
    {
        $client = static::createClient();
        $institution = $this->createInstitutionForSuperAdmin('admin_id_matrix_owner_gate@example.com', 'gate');
        $this->loginAs($client, 'admin_id_matrix_admin_gate@example.com', UserRole::Admin);

        $client->request('GET', '/yonetim/kurumlar/yeni');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/yonetim/kurumlar/'.$institution->getId()->toRfc4122().'/uyeler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminCanOpenIdentitySurfacesAndMembers(): void
    {
        $client = static::createClient();
        $sa = $this->createPrivilegedUser('admin_id_matrix_sa_full@example.com', UserRole::SuperAdmin);
        $institution = $this->createInstitutionForSuperAdmin('admin_id_matrix_owner_full@example.com', 'full', $sa);
        $this->authenticateClient($client, 'admin_id_matrix_sa_full@example.com');

        foreach (['/yonetim/kullanicilar', '/yonetim/kurumlar'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }

        $client->request('GET', '/yonetim/kurumlar/yeni');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="admin_institution_create"]');

        $membersPath = '/yonetim/kurumlar/'.$institution->getId()->toRfc4122().'/uyeler';
        $client->request('GET', $membersPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $institution->getName());
    }

    public function testUnknownUserAndInstitutionDetailReturnNotFound(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_id_matrix_nf_sa@example.com', UserRole::SuperAdmin);
        $unknown = Uuid::v7()->toRfc4122();

        $client->request('GET', '/yonetim/kullanicilar/'.$unknown);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/yonetim/kurumlar/'.$unknown);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidFilterStatusReturnsNotFound(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_id_matrix_filter_sa@example.com', UserRole::SuperAdmin);

        $client->request('GET', '/yonetim/kullanicilar', ['status' => 'not_a_status']);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/yonetim/kurumlar', ['status' => 'not_a_status']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPageSizeIsBoundedOnListPages(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_id_matrix_page_sa@example.com', UserRole::SuperAdmin);

        foreach (['/yonetim/kullanicilar', '/yonetim/kurumlar'] as $path) {
            $client->request('GET', $path, ['page_size' => 1000]);
            self::assertResponseIsSuccessful($path);
            self::assertSelectorTextContains('.admin-pagination', '100/sayfa');
            self::assertSelectorNotExists('a[href*="page_size=1000"]');
        }
    }

    public function testListPagesSendNoStoreHeaders(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_id_matrix_cache_sa@example.com', UserRole::SuperAdmin);

        foreach (['/yonetim/kullanicilar', '/yonetim/kurumlar'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
            self::assertResponseHeaderSame('Pragma', 'no-cache');
        }
    }

    public function testRolesPostRequiresCsrfAndSkipsMutation(): void
    {
        $client = static::createClient();
        $target = $this->createTeacherUser('admin_id_matrix_csrf_target@example.com');
        $rolesBefore = $target->getRoles();
        $this->loginAs($client, 'admin_id_matrix_csrf_sa@example.com', UserRole::SuperAdmin);

        $client->request('POST', '/yonetim/kullanicilar/'.$target->getId()->toRfc4122().'/roller', [
            'admin_user_roles' => [
                'roles' => [UserRole::Moderator->value],
                'reasonCode' => AdminUserRolesRequest::REASON_PRIVILEGE_ADJUSTMENT,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($rolesBefore, $this->reloadUser($target->getId())->getRoles());
    }

    public function testRolesPostRejectsWrongCsrfToken(): void
    {
        $client = static::createClient();
        $target = $this->createTeacherUser('admin_id_matrix_csrf_bad_target@example.com');
        $rolesBefore = $target->getRoles();
        $this->loginAs($client, 'admin_id_matrix_csrf_bad_sa@example.com', UserRole::SuperAdmin);

        $client->request('POST', '/yonetim/kullanicilar/'.$target->getId()->toRfc4122().'/roller', [
            'admin_user_roles' => [
                'roles' => [UserRole::Moderator->value],
                'reasonCode' => AdminUserRolesRequest::REASON_PRIVILEGE_ADJUSTMENT,
                'confirm' => '1',
                '_token' => 'definitely-not-a-valid-csrf-token',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($rolesBefore, $this->reloadUser($target->getId())->getRoles());
    }

    public function testRolesPostRejectsCrossTargetCsrfToken(): void
    {
        $client = static::createClient();
        $targetA = $this->createTeacherUser('admin_id_matrix_csrf_x_a@example.com');
        $targetB = $this->createTeacherUser('admin_id_matrix_csrf_x_b@example.com');
        $rolesBeforeB = $targetB->getRoles();
        $this->loginAs($client, 'admin_id_matrix_csrf_x_sa@example.com', UserRole::SuperAdmin);

        $crawler = $client->request('GET', '/yonetim/kullanicilar/'.$targetA->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="admin_user_roles[_token]"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        $client->request('POST', '/yonetim/kullanicilar/'.$targetB->getId()->toRfc4122().'/roller', [
            'admin_user_roles' => [
                'roles' => [UserRole::Moderator->value],
                'reasonCode' => AdminUserRolesRequest::REASON_PRIVILEGE_ADJUSTMENT,
                'confirm' => '1',
                '_token' => $token,
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($rolesBeforeB, $this->reloadUser($targetB->getId())->getRoles());
    }

    public function testUserStatusRateLimitReturns429WithoutMutation(): void
    {
        $client = static::createClient();
        $target = $this->createTeacherUser('admin_id_matrix_rl_target@example.com');
        $statusBefore = $target->getStatus();
        $this->loginAs($client, 'admin_id_matrix_rl_sa@example.com', UserRole::SuperAdmin);
        $path = '/yonetim/kullanicilar/'.$target->getId()->toRfc4122().'/durum';

        $saw429 = false;
        $retryAfter = null;
        for ($i = 0; $i < 6; ++$i) {
            $client->request('POST', $path, [
                'admin_user_status' => [
                    'action' => AdminUserStatusRequest::ACTION_SUSPEND,
                    'reasonCode' => AdminUserStatusRequest::REASON_POLICY_VIOLATION,
                    'confirm' => '1',
                ],
            ]);
            if (429 === $client->getResponse()->getStatusCode()) {
                $saw429 = true;
                $retryAfter = $client->getResponse()->headers->get('Retry-After');
                break;
            }
            self::assertResponseStatusCodeSame(403);
        }

        self::assertTrue($saw429);
        self::assertNotNull($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', (string) $retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
        self::assertSame($statusBefore, $this->reloadUser($target->getId())->getStatus());
    }

    public function testStaleAdminDeniedOnUsersList(): void
    {
        $client = static::createClient();
        $user = $this->loginAs($client, 'admin_id_matrix_stale_admin@example.com', UserRole::Admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/kullanicilar');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotManageProtectedSuperAdminTarget(): void
    {
        $client = static::createClient();
        $this->createPrivilegedUser('admin_id_matrix_sa_target@example.com', UserRole::SuperAdmin);
        $saTarget = $this->reloadUserByEmail('admin_id_matrix_sa_target@example.com');
        $rolesBefore = $saTarget->getRoles();
        $this->loginAs($client, 'admin_id_matrix_admin_sa@example.com', UserRole::Admin);

        $client->request('GET', '/yonetim/kullanicilar/'.$saTarget->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Süper yönetici hesabı uygulama üzerinden yönetilemez.');
        self::assertSelectorNotExists('form[name="admin_user_roles"]');

        $client->request('POST', '/yonetim/kullanicilar/'.$saTarget->getId()->toRfc4122().'/roller', [
            'admin_user_roles' => [
                'roles' => [UserRole::Teacher->value],
                'reasonCode' => AdminUserRolesRequest::REASON_PRIVILEGE_ADJUSTMENT,
                'confirm' => '1',
            ],
        ]);
        self::assertContains($client->getResponse()->getStatusCode(), [403, 302]);
        if (302 === $client->getResponse()->getStatusCode()) {
            $client->followRedirect();
            self::assertSelectorExists('.admin-flash');
        }
        self::assertSame($rolesBefore, $this->reloadUser($saTarget->getId())->getRoles());
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): User
    {
        $user = $this->createPrivilegedUser($email, $role);
        $this->authenticateClient($client, $email);

        return $user;
    }

    private function authenticateClient(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();
    }

    private function createPrivilegedUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Min', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);

        return $user;
    }

    private function createTeacherUser(string $email): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Hedef', 'Ogretmen', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);

        return $user;
    }

    private function createInstitutionForSuperAdmin(string $ownerEmail, string $suffix, ?User $superAdmin = null): Institution
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        if (!$superAdmin instanceof User) {
            $superAdmin = $this->createPrivilegedUser(
                'admin_id_matrix_sa_inst_'.$suffix.'@example.com',
                UserRole::SuperAdmin,
            );
            self::bootKernel();
        }
        $owner = $this->createTeacherUser($ownerEmail);
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $freshSa = $users->findOneById($superAdmin->getId());
        self::assertInstanceOf(User::class, $freshSa);
        $freshOwner = $users->findOneById($owner->getId());
        self::assertInstanceOf(User::class, $freshOwner);
        /** @var InstitutionCreator $creator */
        $creator = static::getContainer()->get(InstitutionCreator::class);
        $institution = $creator->create(
            $freshSa,
            $freshOwner,
            'Admin Id Matrix '.$suffix,
            InstitutionType::School,
            'platform_setup',
        );

        return $institution;
    }

    private function reloadUser(Uuid $id): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneById($id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function reloadUserByEmail(string $email): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail(strtolower($email));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            if ($conn->createSchemaManager()->tablesExist(['institution_memberships', 'institutions'])) {
                $conn->executeStatement(
                    "DELETE im FROM institution_memberships im INNER JOIN institutions i ON i.id = im.institution_id WHERE i.normalized_name LIKE 'admin id matrix%'",
                );
                $conn->executeStatement(
                    "DELETE FROM institutions WHERE normalized_name LIKE 'admin id matrix%'",
                );
            }
            if ($conn->createSchemaManager()->tablesExist(['users'])) {
                $conn->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'admin_id_matrix_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
