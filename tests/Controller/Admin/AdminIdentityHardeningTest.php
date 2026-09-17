<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Dto\AdminInstitutionStatusRequest;
use App\Dto\AdminMembershipStatusRequest;
use App\Dto\AdminUserStatusRequest;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * Merge-pre hardening for Stage 2.21 admin identity / institution panel.
 */
final class AdminIdentityHardeningTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testTeacherAndInstitutionOwnerForbiddenOnAdminIdentitySurfaces(): void
    {
        $client = static::createClient();
        $ownerEmail = 'admin_id_harden_owner_gate@example.com';
        $this->createInstitutionForSuperAdmin($ownerEmail, 'owner_gate');

        $surfaces = ['/yonetim/kullanicilar', '/yonetim/kurumlar'];

        $this->authenticateClient($client, $this->createTeacherUser('admin_id_harden_teacher_gate@example.com')->getEmail());
        foreach ($surfaces as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, 'teacher '.$path);
        }
        $client->restart();

        $this->authenticateClient($client, $ownerEmail);
        foreach ($surfaces as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, 'institution owner '.$path);
        }
    }

    public function testStaleSuspendedAdminDeniedOnUsersList(): void
    {
        $client = static::createClient();
        $user = $this->loginAs($client, 'admin_id_harden_stale_susp@example.com', UserRole::Admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/kullanicilar');
        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [403, 302],
            'Suspended actor must be denied (403) or logged out (302 to login)',
        );
        if (302 === $client->getResponse()->getStatusCode()) {
            self::assertResponseRedirects('/giris');
        }
    }

    public function testStaleArchivedAdminDeniedOnUsersList(): void
    {
        $client = static::createClient();
        $user = $this->loginAs($client, 'admin_id_harden_stale_arch@example.com', UserRole::Admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Archived->value, $user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/kullanicilar');
        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [403, 302],
            'Archived actor must be denied (403) or logged out (302 to login)',
        );
        if (302 === $client->getResponse()->getStatusCode()) {
            self::assertResponseRedirects('/giris');
        }
    }

    public function testStaleRoleStrippedAdminDeniedOnUsersList(): void
    {
        $client = static::createClient();
        $user = $this->loginAs($client, 'admin_id_harden_stale_role@example.com', UserRole::Admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET global_roles = ? WHERE id = ?',
            [json_encode([], \JSON_THROW_ON_ERROR), $user->getId()->toBinary()],
        );

        $client->request('GET', '/yonetim/kullanicilar');
        self::assertContains($client->getResponse()->getStatusCode(), [403, 302]);
        if (302 === $client->getResponse()->getStatusCode()) {
            self::assertResponseRedirects('/giris');
        }
    }

    public function testStatusPostMissingCsrf403(): void
    {
        $client = static::createClient();
        $target = $this->createTeacherUser('admin_id_harden_csrf_miss_target@example.com');
        $statusBefore = $target->getStatus();
        $this->loginAs($client, 'admin_id_harden_csrf_miss_sa@example.com', UserRole::SuperAdmin);

        $client->request('POST', '/yonetim/kullanicilar/'.$target->getId()->toRfc4122().'/durum', [
            'admin_user_status' => [
                'action' => AdminUserStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminUserStatusRequest::REASON_POLICY_VIOLATION,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($statusBefore, $this->reloadUser($target->getId())->getStatus());
    }

    public function testStatusPostCrossActionToken403(): void
    {
        $client = static::createClient();
        $target = $this->createTeacherUser('admin_id_harden_csrf_x_target@example.com');
        $statusBefore = $target->getStatus();
        $this->loginAs($client, 'admin_id_harden_csrf_x_sa@example.com', UserRole::SuperAdmin);
        $id = $target->getId()->toRfc4122();

        $rolesToken = $this->csrfTokenValue($client, 'admin_user_roles_'.$id);
        $client->request('POST', '/yonetim/kullanicilar/'.$id.'/durum', [
            'admin_user_status' => [
                'action' => AdminUserStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminUserStatusRequest::REASON_POLICY_VIOLATION,
                'confirm' => '1',
                '_token' => $rolesToken,
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($statusBefore, $this->reloadUser($target->getId())->getStatus());
    }

    public function testInstitutionStatusMissingCsrf403(): void
    {
        $client = static::createClient();
        $sa = $this->createPrivilegedUser('admin_id_harden_inst_csrf_sa@example.com', UserRole::SuperAdmin);
        $institution = $this->createActiveInstitution($sa, 'admin_id_harden_inst_csrf_owner@example.com', 'inst_csrf');
        $this->authenticateClient($client, $sa->getEmail());

        $client->request('POST', '/yonetim/kurumlar/'.$institution->getId()->toRfc4122().'/durum', [
            'admin_institution_status' => [
                'action' => AdminInstitutionStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminInstitutionStatusRequest::REASON_POLICY,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInstitutionStatusRateLimit429WithValidCsrf(): void
    {
        $client = static::createClient();
        $sa = $this->createPrivilegedUser('admin_id_harden_inst_rl_sa@example.com', UserRole::SuperAdmin);
        $institution = $this->createActiveInstitution($sa, 'admin_id_harden_inst_rl_owner@example.com', 'inst_rl');
        $this->authenticateClient($client, $sa->getEmail());
        $institutionId = $institution->getId()->toRfc4122();
        $path = '/yonetim/kurumlar/'.$institutionId.'/durum';
        $actions = [
            AdminInstitutionStatusRequest::ACTION_SUSPEND,
            AdminInstitutionStatusRequest::ACTION_ACTIVATE,
            AdminInstitutionStatusRequest::ACTION_SUSPEND,
            AdminInstitutionStatusRequest::ACTION_ACTIVATE,
            AdminInstitutionStatusRequest::ACTION_SUSPEND,
            AdminInstitutionStatusRequest::ACTION_ACTIVATE,
        ];

        $saw429 = false;
        for ($i = 0; $i < 6; ++$i) {
            $token = $this->csrfTokenValue($client, 'admin_institution_status_'.$institutionId);
            $client->request('POST', $path, [
                'admin_institution_status' => [
                    'action' => $actions[$i],
                    'reasonCode' => AdminInstitutionStatusRequest::REASON_POLICY,
                    'confirm' => '1',
                    '_token' => $token,
                ],
            ]);
            if (429 === $client->getResponse()->getStatusCode()) {
                $saw429 = true;
                $body = (string) $client->getResponse()->getContent();
                self::assertStringNotContainsString('$2y$', $body);
                self::assertStringNotContainsString('admin_id_harden_inst_rl_owner@example.com', $body);
                self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
                break;
            }
            self::assertResponseStatusCodeSame(302);
        }
        self::assertTrue($saw429);
    }

    public function testMembershipCrossInstitutionIdReturns404(): void
    {
        $client = static::createClient();
        $sa = $this->createPrivilegedUser('admin_id_harden_mem_x_sa@example.com', UserRole::SuperAdmin);
        $institutionA = $this->createActiveInstitution($sa, 'admin_id_harden_mem_x_owner_a@example.com', 'mem_x_a');
        $institutionB = $this->createActiveInstitution($sa, 'admin_id_harden_mem_x_owner_b@example.com', 'mem_x_b');
        $member = $this->addTeacherMember($institutionA, 'admin_id_harden_mem_x_owner_a@example.com', 'admin_id_harden_mem_x_teacher@example.com');
        $this->authenticateClient($client, $sa->getEmail());

        $token = $this->csrfTokenValue(
            $client,
            'admin_membership_status_'.$member->getId()->toRfc4122(),
            ['institutionId' => $institutionA->getId()->toRfc4122()],
        );
        $client->request('POST', '/yonetim/kurumlar/'.$institutionB->getId()->toRfc4122().'/uyeler/'.$member->getId()->toRfc4122().'/durum', [
            'admin_membership_status' => [
                'action' => AdminMembershipStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminMembershipStatusRequest::REASON_POLICY,
                'confirm' => '1',
                '_token' => $token,
            ],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMembershipStatusMissingCsrf403(): void
    {
        $client = static::createClient();
        $sa = $this->createPrivilegedUser('admin_id_harden_mem_csrf_sa@example.com', UserRole::SuperAdmin);
        $institution = $this->createActiveInstitution($sa, 'admin_id_harden_mem_csrf_owner@example.com', 'mem_csrf');
        $member = $this->addTeacherMember($institution, 'admin_id_harden_mem_csrf_owner@example.com', 'admin_id_harden_mem_csrf_teacher@example.com');
        $this->authenticateClient($client, $sa->getEmail());

        $client->request('POST', '/yonetim/kurumlar/'.$institution->getId()->toRfc4122().'/uyeler/'.$member->getId()->toRfc4122().'/durum', [
            'admin_membership_status' => [
                'action' => AdminMembershipStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminMembershipStatusRequest::REASON_POLICY,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMembershipStatusRateLimit429(): void
    {
        $client = static::createClient();
        $sa = $this->createPrivilegedUser('admin_id_harden_mem_rl_sa@example.com', UserRole::SuperAdmin);
        $institution = $this->createActiveInstitution($sa, 'admin_id_harden_mem_rl_owner@example.com', 'mem_rl');
        $member = $this->addTeacherMember($institution, 'admin_id_harden_mem_rl_owner@example.com', 'admin_id_harden_mem_rl_teacher@example.com');
        $this->authenticateClient($client, $sa->getEmail());
        $institutionId = $institution->getId()->toRfc4122();
        $membershipId = $member->getId()->toRfc4122();
        $path = '/yonetim/kurumlar/'.$institutionId.'/uyeler/'.$membershipId.'/durum';
        $actions = [
            AdminMembershipStatusRequest::ACTION_SUSPEND,
            AdminMembershipStatusRequest::ACTION_REACTIVATE,
            AdminMembershipStatusRequest::ACTION_SUSPEND,
            AdminMembershipStatusRequest::ACTION_REACTIVATE,
            AdminMembershipStatusRequest::ACTION_SUSPEND,
            AdminMembershipStatusRequest::ACTION_REACTIVATE,
        ];

        $saw429 = false;
        for ($i = 0; $i < 6; ++$i) {
            $token = $this->csrfTokenValue(
                $client,
                'admin_membership_status_'.$membershipId,
                ['institutionId' => $institutionId],
            );
            $client->request('POST', $path, [
                'admin_membership_status' => [
                    'action' => $actions[$i],
                    'reasonCode' => AdminMembershipStatusRequest::REASON_POLICY,
                    'confirm' => '1',
                    '_token' => $token,
                ],
            ]);
            if (429 === $client->getResponse()->getStatusCode()) {
                $saw429 = true;
                self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
                break;
            }
            self::assertResponseStatusCodeSame(302);
        }
        self::assertTrue($saw429);
    }

    public function testSearchWildcardDoesNotErrorAndEscapes(): void
    {
        $client = static::createClient();
        $this->createTeacherUser('admin_id_harden_wildcard_mark@example.com');
        $this->loginAs($client, 'admin_id_harden_wildcard_sa@example.com', UserRole::SuperAdmin);

        $client->request('GET', '/yonetim/kullanicilar', ['q' => '%_!']);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/yonetim/kullanicilar', ['q' => str_repeat('x', 101)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPaginationPreservesFiltersInLinks(): void
    {
        $client = static::createClient();
        for ($i = 0; $i < 26; ++$i) {
            $this->createTeacherUser(\sprintf('admin_id_harden_pag_%02d@example.com', $i));
        }
        $this->loginAs($client, 'admin_id_harden_pag_sa@example.com', UserRole::SuperAdmin);

        $client->request('GET', '/yonetim/kullanicilar', [
            'q' => 'admin_id_harden_pag_',
            'status' => UserStatus::Active->value,
            'page' => 1,
            'page_size' => 25,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="page=2"]');
        self::assertSelectorExists('a[href*="q=admin_id_harden_pag_"]');
        self::assertSelectorExists('a[href*="status='.UserStatus::Active->value.'"]');
    }

    public function testRedirectAfterStatusMutationHasNoStoreHeaders(): void
    {
        $client = static::createClient();
        $target = $this->createTeacherUser('admin_id_harden_nostore_target@example.com');
        $this->loginAs($client, 'admin_id_harden_nostore_sa@example.com', UserRole::SuperAdmin);
        $id = $target->getId()->toRfc4122();
        $token = $this->csrfTokenValue($client, 'admin_user_status_'.$id);

        $client->request('POST', '/yonetim/kullanicilar/'.$id.'/durum', [
            'admin_user_status' => [
                'action' => AdminUserStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminUserStatusRequest::REASON_POLICY_VIOLATION,
                'confirm' => '1',
                '_token' => $token,
            ],
        ]);
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Pragma', 'no-cache');
    }

    public function testCanarySecretsAbsentFromUserDetailAndAuditLinkPage(): void
    {
        $client = static::createClient();
        $canary = $this->createCanaryUserWithSecrets();
        $hash = $this->fetchPasswordHash($canary->getId());
        $this->loginAs($client, 'admin_id_harden_canary_sa@example.com', UserRole::SuperAdmin);

        $canaries = [
            'password_hash' => $hash,
            'dsn' => 'mysql://canary_harden:CANARY_DSN_SECRET@127.0.0.1:3306/testlig_harden',
            'php_path' => 'C:\\xampp\\php\\CANARY_PHP_INI_PATH.ini',
            'session' => 'CANARY_PHPSESSID_harden_'.bin2hex(random_bytes(4)),
            'app_secret' => 'CANARY_APP_SECRET_harden_'.bin2hex(random_bytes(6)),
            'redis_dsn' => 'redis://:CANARY_REDIS_PASS@127.0.0.1:6379/9',
        ];

        $detailPath = '/yonetim/kullanicilar/'.$canary->getId()->toRfc4122();
        $client->request('GET', $detailPath);
        self::assertResponseIsSuccessful();
        $detailHtml = (string) $client->getResponse()->getContent();
        foreach ($canaries as $label => $needle) {
            self::assertStringNotContainsString($needle, $detailHtml, 'detail leaks '.$label);
        }
        self::assertDoesNotMatchRegularExpression('/\$2y\$/', $detailHtml);

        $client->request('GET', '/yonetim/denetim');
        self::assertResponseIsSuccessful();
        $auditHtml = (string) $client->getResponse()->getContent();
        foreach ($canaries as $label => $needle) {
            self::assertStringNotContainsString($needle, $auditHtml, 'audit leaks '.$label);
        }
        self::assertSelectorExists('a[href="/yonetim/denetim"]');
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

    /**
     * CSRF tokens must be minted during a real request (Twig csrf_token) so the session cookie persists them.
     *
     * @param array{institutionId?: string} $context
     */
    private function csrfTokenValue(KernelBrowser $client, string $tokenId, array $context = []): string
    {
        if (str_starts_with($tokenId, 'admin_user_status_')) {
            $userId = substr($tokenId, \strlen('admin_user_status_'));

            return $this->csrfTokenFromInput(
                $client,
                '/yonetim/kullanicilar/'.$userId,
                'admin_user_status[_token]',
            );
        }

        if (str_starts_with($tokenId, 'admin_user_roles_')) {
            $userId = substr($tokenId, \strlen('admin_user_roles_'));

            return $this->csrfTokenFromInput(
                $client,
                '/yonetim/kullanicilar/'.$userId,
                'admin_user_roles[_token]',
            );
        }

        if (str_starts_with($tokenId, 'admin_institution_status_')) {
            $institutionId = substr($tokenId, \strlen('admin_institution_status_'));

            return $this->csrfTokenFromInput(
                $client,
                '/yonetim/kurumlar/'.$institutionId,
                'admin_institution_status[_token]',
            );
        }

        if (str_starts_with($tokenId, 'admin_membership_status_')) {
            $membershipId = substr($tokenId, \strlen('admin_membership_status_'));
            $institutionId = $context['institutionId'] ?? null;
            self::assertIsString($institutionId);
            $crawler = $client->request('GET', '/yonetim/kurumlar/'.$institutionId.'/uyeler');
            self::assertResponseIsSuccessful();
            $token = null;
            $crawler->filter('form')->each(static function (Crawler $form) use ($membershipId, &$token): void {
                if (null !== $token) {
                    return;
                }
                $action = (string) $form->attr('action');
                if (!str_contains($action, $membershipId) || !str_ends_with($action, '/durum')) {
                    return;
                }
                $value = $form->filter('input[name="admin_membership_status[_token]"]')->attr('value');
                if (\is_string($value) && '' !== $value) {
                    $token = $value;
                }
            });
            self::assertIsString($token, 'Expected membership status CSRF form for '.$membershipId);

            return $token;
        }

        self::fail('Unsupported CSRF token id: '.$tokenId);
    }

    private function csrfTokenFromInput(KernelBrowser $client, string $path, string $inputName): string
    {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="'.$inputName.'"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
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
                'admin_id_harden_sa_inst_'.$suffix.'@example.com',
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
            'Admin Id Harden '.$suffix,
            InstitutionType::School,
            'platform_setup',
        );

        return $institution;
    }

    private function createActiveInstitution(User $superAdmin, string $ownerEmail, string $suffix): Institution
    {
        $institution = $this->createInstitutionForSuperAdmin($ownerEmail, $suffix, $superAdmin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var InstitutionRepository $institutions */
        $institutions = static::getContainer()->get(InstitutionRepository::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        /** @var InstitutionStatusManager $statusManager */
        $statusManager = static::getContainer()->get(InstitutionStatusManager::class);
        $freshInstitution = $institutions->findOneById($institution->getId());
        self::assertInstanceOf(Institution::class, $freshInstitution);
        $freshSa = $users->findOneById($superAdmin->getId());
        self::assertInstanceOf(User::class, $freshSa);
        $statusManager->activate($freshInstitution, $freshSa, AdminInstitutionStatusRequest::REASON_LIFECYCLE);

        return $freshInstitution;
    }

    private function addTeacherMember(Institution $institution, string $ownerEmail, string $teacherEmail): InstitutionMembership
    {
        $teacher = $this->createTeacherUser($teacherEmail);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var InstitutionRepository $institutions */
        $institutions = static::getContainer()->get(InstitutionRepository::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        /** @var InstitutionMembershipManager $membershipManager */
        $membershipManager = static::getContainer()->get(InstitutionMembershipManager::class);
        /** @var InstitutionMembershipRepository $membershipRepo */
        $membershipRepo = static::getContainer()->get(InstitutionMembershipRepository::class);

        $freshInstitution = $institutions->findOneById($institution->getId());
        self::assertInstanceOf(Institution::class, $freshInstitution);
        $freshOwner = $users->findOneByNormalizedEmail(strtolower($ownerEmail));
        self::assertInstanceOf(User::class, $freshOwner);
        $freshTeacher = $users->findOneById($teacher->getId());
        self::assertInstanceOf(User::class, $freshTeacher);

        $membershipManager->addMember(
            $freshInstitution,
            $freshOwner,
            $freshTeacher,
            InstitutionMembershipRole::Teacher,
            'harden_add_teacher',
        );

        $membership = $membershipRepo->findActiveMembership($freshTeacher, $freshInstitution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);

        return $membership;
    }

    private function createCanaryUserWithSecrets(): User
    {
        return $this->createTeacherUser('admin_id_harden_canary_user@example.com');
    }

    private function fetchPasswordHash(Uuid $id): string
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hash = $em->getConnection()->fetchOne(
            'SELECT password FROM users WHERE id = ?',
            [$id->toBinary()],
        );
        self::assertIsString($hash);

        return $hash;
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

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            if ($conn->createSchemaManager()->tablesExist(['institution_memberships', 'institutions'])) {
                $conn->executeStatement(
                    "DELETE im FROM institution_memberships im INNER JOIN institutions i ON i.id = im.institution_id WHERE i.normalized_name LIKE 'admin id harden%'",
                );
                $conn->executeStatement(
                    "DELETE FROM institutions WHERE normalized_name LIKE 'admin id harden%'",
                );
            }
            if ($conn->createSchemaManager()->tablesExist(['users'])) {
                $conn->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'admin_id_harden_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
