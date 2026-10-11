<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\AdminUserRolesRequest;
use App\Entity\TeacherApplication;
use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\TeacherApplicationRepository;
use App\Repository\UserRepository;
use App\Service\TeacherApplicationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Service\UserGlobalRoleManager;
use App\Service\UserStatusManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminTeacherRoleGrantHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private string $pendingA = '';

    private string $pendingB = '';

    protected function setUp(): void
    {
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testApprovalDoesNotGrantRoleAndExplicitGrantKeepsOtherRoles(): void
    {
        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $before = $this->privilegeSnapshot();
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-a@example.com'));
        self::assertSame(0, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());

        $this->withKernel(function (): void {
            $roles = static::getContainer()->get(UserGlobalRoleManager::class);
            self::assertInstanceOf(UserGlobalRoleManager::class, $roles);
            $roles->addRole($this->user('tgrant-a@example.com'), UserRole::Moderator, $this->user('tgrant-sa@example.com'), 'privilege_adjustment');
        });
        $rolesBeforeGrant = $this->roles('tgrant-a@example.com');
        self::assertContains(UserRole::Student->value, $rolesBeforeGrant);
        self::assertContains(UserRole::Moderator->value, $rolesBeforeGrant);
        $auditsBeforeGrant = $this->auditCount('role_changed');

        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Öğretmen rolü ver')->form([
            'confirm' => '1',
        ]));
        $client->followRedirect();

        $rolesAfter = $this->roles('tgrant-a@example.com');
        self::assertContains(UserRole::Teacher->value, $rolesAfter);
        self::assertContains(UserRole::Student->value, $rolesAfter);
        self::assertContains(UserRole::Moderator->value, $rolesAfter);
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-b@example.com'));
        self::assertSame($auditsBeforeGrant + 1, $this->auditCount('role_changed'));
        self::assertSame('teacher_application_'.$this->pendingA, $this->latestRoleReason());
        self::assertSame($before, $this->privilegeSnapshot());

        $again = $this->auditCount('role_changed');
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Öğretmen rolü ver')->form([
            'confirm' => '1',
        ]));
        $client->followRedirect();
        self::assertSame($rolesAfter, $this->roles('tgrant-a@example.com'));
        self::assertSame($again, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testPendingRejectedWithdrawnAndSupersededApplicationsAreRefused(): void
    {
        $withdrawn = $this->openApplication('tgrant-withdrawn@example.com', 'Deniz', 'Kara');
        $superseded = $this->openApplication('tgrant-superseded@example.com', 'Ege', 'Kara');
        $this->withKernel(function () use ($withdrawn, $superseded): void {
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            $repo = static::getContainer()->get(TeacherApplicationRepository::class);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            self::assertInstanceOf(TeacherApplicationRepository::class, $repo);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $applications->markRejected($this->user('tgrant-sa@example.com'), Uuid::fromString($this->pendingB), 'incomplete_documents');
            $applications->withdraw($this->user('tgrant-withdrawn@example.com'), Uuid::fromString($withdrawn));
            $row = $repo->findOneById(Uuid::fromString($superseded));
            self::assertInstanceOf(TeacherApplication::class, $row);
            $row->markSuperseded(new \DateTimeImmutable('now'));
            $em->flush();
        });
        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $before = $this->privilegeSnapshot();
        $audits = $this->auditCount('role_changed');

        foreach ([
            $this->pendingA => OnboardingApplicationStatus::Pending->value,
            $this->pendingB => OnboardingApplicationStatus::Rejected->value,
            $withdrawn => OnboardingApplicationStatus::Withdrawn->value,
            $superseded => OnboardingApplicationStatus::Superseded->value,
        ] as $id => $status) {
            $this->postGrant($client, $id);
            self::assertResponseRedirects('/yonetim/ogretmen-basvurulari/'.$id);
            self::assertSame($status, $this->applicationStatus($id));
        }

        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-a@example.com'));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-b@example.com'));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-withdrawn@example.com'));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-superseded@example.com'));
        self::assertSame($audits, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testUnauthorizedActorsAndSuspendedSuperAdminCannotGrant(): void
    {
        $this->approve($this->pendingA);
        $this->assertGrantDenied('tgrant-admin@example.com');
        $this->assertGrantDenied('tgrant-teacher@example.com');
        $this->assertGrantDenied('tgrant-a@example.com');

        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $client->request('GET', '/hesabim');
        $token = $this->csrf($client, 'teacher_application_grant_role');
        $this->setStatus('tgrant-sa@example.com', UserStatus::Suspended);
        $before = $this->privilegeSnapshot();
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/ogretmen-rolu', [
            '_token' => $token,
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/giris');
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-a@example.com'));
        self::assertSame(0, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testSuperAdminCannotGrantTheRoleOnTheirOwnApplication(): void
    {
        $id = $this->openApplication('tgrant-self@example.com', 'Selin', 'Aydın');
        $this->approve($id);
        $this->withKernel(function (): void {
            $user = $this->user('tgrant-self@example.com');
            $user->addGlobalRole(UserRole::SuperAdmin);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->flush();
        });
        $client = $this->browser();
        $this->login($client, 'tgrant-self@example.com');
        $before = $this->privilegeSnapshot();
        $this->postGrant($client, $id);
        $client->followRedirect();

        self::assertStringContainsString('Kendi hesabınız üzerinde bu işlem yapılamaz.', (string) $client->getResponse()->getContent());
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-self@example.com'));
        self::assertSame(0, $this->auditCount('role_changed'));
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($id));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testSuspendedAndUnverifiedApplicantsAreRefused(): void
    {
        $unverified = $this->openApplication('tgrant-unverified@example.com', 'Pelin', 'Ak');
        $this->approve($this->pendingA);
        $this->approve($unverified);
        $this->withKernel(function () use ($unverified): void {
            $status = static::getContainer()->get(UserStatusManager::class);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(UserStatusManager::class, $status);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $status->suspend($this->user('tgrant-a@example.com'), $this->user('tgrant-sa@example.com'), 'review_hold');
            $em->getConnection()->executeStatement(
                'UPDATE users SET email_verified_at = NULL WHERE email = ?',
                ['tgrant-unverified@example.com'],
            );
            unset($unverified);
        });
        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $before = $this->privilegeSnapshot();
        $this->postGrant($client, $this->pendingA);
        self::assertResponseRedirects('/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $this->postGrant($client, $unverified);
        self::assertResponseRedirects('/yonetim/ogretmen-basvurulari/'.$unverified);

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($unverified));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-a@example.com'));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-unverified@example.com'));
        self::assertSame(0, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testExpertAndHeadTeacherAccountsStayUnchanged(): void
    {
        $expert = $this->openApplication('tgrant-expert@example.com', 'Eda', 'Usta');
        $head = $this->openApplication('tgrant-head@example.com', 'Mert', 'Usta');
        $this->approve($expert);
        $this->approve($head);
        $this->withKernel(function (): void {
            $roles = static::getContainer()->get(UserGlobalRoleManager::class);
            self::assertInstanceOf(UserGlobalRoleManager::class, $roles);
            $actor = $this->user('tgrant-sa@example.com');
            $roles->addRole($this->user('tgrant-expert@example.com'), UserRole::ExpertTeacher, $actor, 'privilege_adjustment');
            $roles->addRole($this->user('tgrant-head@example.com'), UserRole::HeadTeacher, $actor, 'privilege_adjustment');
        });
        $expertRoles = $this->roles('tgrant-expert@example.com');
        $headRoles = $this->roles('tgrant-head@example.com');
        $audits = $this->auditCount('role_changed');
        $before = $this->privilegeSnapshot();
        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $this->postGrant($client, $expert);
        $this->postGrant($client, $head);

        self::assertSame($expertRoles, $this->roles('tgrant-expert@example.com'));
        self::assertSame($headRoles, $this->roles('tgrant-head@example.com'));
        self::assertNotContains(UserRole::Teacher->value, $expertRoles);
        self::assertNotContains(UserRole::Teacher->value, $headRoles);
        self::assertSame($audits, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testBrokenReferenceMissingConfirmAndInvalidCsrfWriteNothing(): void
    {
        $this->approve($this->pendingA);
        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $token = $this->csrf($client, 'teacher_application_grant_role');
        $before = $this->privilegeSnapshot();
        $missing = Uuid::v7()->toRfc4122();

        $client->request('POST', '/yonetim/ogretmen-basvurulari/not-a-uuid/ogretmen-rolu', [
            '_token' => $token,
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$missing.'/ogretmen-rolu', [
            '_token' => $token,
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/ogretmen-rolu', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/ogretmen-rolu', [
            '_token' => 'invalid',
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);

        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-a@example.com'));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-b@example.com'));
        self::assertSame(0, $this->auditCount('role_changed'));
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testGetWritesNothing(): void
    {
        $this->approve($this->pendingA);
        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $before = $this->privilegeSnapshot();
        $applications = $this->applicationCount();
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        self::assertResponseIsSuccessful();
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Başvuru onayı öğretmen rolü vermez.', $page);
        self::assertStringContainsString('Öğretmen rolünü vermeyi onaylıyorum', $page);
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingB);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Öğretmen rolü ver', (string) $client->getResponse()->getContent());

        self::assertSame($applications, $this->applicationCount());
        self::assertSame(0, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testExistingUserRoleScreenStillReplacesRoles(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('tgrant-screen@example.com', UserRole::Student, 'Ela', 'Ekran');
        });
        $id = $this->userId('tgrant-screen@example.com');
        $client = $this->browser();
        $this->login($client, 'tgrant-sa@example.com');
        $before = $this->privilegeSnapshot();
        $applications = $this->applicationCount();
        $crawler = $client->request('GET', '/yonetim/kullanicilar/'.$id);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="admin_user_roles[_token]"]')->attr('value');
        self::assertIsString($token);
        $client->request('POST', '/yonetim/kullanicilar/'.$id.'/roller', [
            'admin_user_roles' => [
                'roles' => [UserRole::Student->value, UserRole::Moderator->value],
                'reasonCode' => AdminUserRolesRequest::REASON_PRIVILEGE_ADJUSTMENT,
                'confirm' => '1',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/yonetim/kullanicilar/'.$id);

        $roles = $this->roles('tgrant-screen@example.com');
        self::assertContains(UserRole::Student->value, $roles);
        self::assertContains(UserRole::Moderator->value, $roles);
        self::assertNotContains(UserRole::Teacher->value, $roles);
        self::assertSame(1, $this->auditCount('role_changed'));
        self::assertSame('privilege_adjustment', $this->latestRoleReason());
        self::assertSame($applications, $this->applicationCount());
        self::assertSame($before, $this->privilegeSnapshot());
    }

    private function assertGrantDenied(string $email): void
    {
        $client = $this->browser();
        $this->login($client, $email);
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
        $token = $this->csrf($client, 'teacher_application_grant_role');
        $before = $this->privilegeSnapshot();
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/ogretmen-rolu', [
            '_token' => $token,
            'confirm' => '1',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('tgrant-a@example.com'));
        self::assertSame(0, $this->auditCount('role_changed'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    private function postGrant(KernelBrowser $client, string $id): void
    {
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$id);
        $token = $this->csrf($client, 'teacher_application_grant_role');
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$id.'/ogretmen-rolu', [
            '_token' => $token,
            'confirm' => '1',
        ]);
    }

    private function approve(string $id): void
    {
        $this->withKernel(function () use ($id): void {
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            $applications->markApproved($this->user('tgrant-sa@example.com'), Uuid::fromString($id), null);
        });
    }

    private function openApplication(string $email, string $first, string $last): string
    {
        $id = $this->withKernel(function () use ($email, $first, $last): string {
            $this->createActive($email, UserRole::Student, $first, $last);
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);

            return $applications->submit($this->user($email))->getId()->toRfc4122();
        });

        return \is_string($id) ? $id : '';
    }

    private function seed(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('tgrant-sa@example.com', UserRole::SuperAdmin, 'Selin', 'Aydın');
            $this->createActive('tgrant-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
            $this->createActive('tgrant-teacher@example.com', UserRole::Teacher, 'Ece', 'Koç');
            $this->createActive('tgrant-a@example.com', UserRole::Student, 'Ada', 'Yılmaz');
            $this->createActive('tgrant-b@example.com', UserRole::Student, 'Bora', 'Yılmaz');
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            $this->pendingA = $applications->submit($this->user('tgrant-a@example.com'))->getId()->toRfc4122();
            $this->pendingB = $applications->submit($this->user('tgrant-b@example.com'))->getId()->toRfc4122();
        });
    }

    private function createActive(string $email, UserRole $role, string $first, string $last): void
    {
        $factory = static::getContainer()->get(UserFactory::class);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $lifecycle->markEmailVerifiedAndActivate($user);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->flush();
        }
    }

    private function purge(): void
    {
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $ids = $em->getConnection()->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'tgrant-%'");
            if ([] === $ids) {
                return;
            }
            $em->getConnection()->executeStatement('DELETE FROM teacher_applications WHERE user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM security_audit_events WHERE actor_user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM users WHERE email LIKE ?', ['tgrant-%']);
        });
    }

    /**
     * @return array<string, int>
     */
    private function privilegeSnapshot(): array
    {
        return [
            'institution_created' => $this->auditCount('institution_created'),
            'institution_member_added' => $this->auditCount('institution_member_added'),
            'institution_member_role_changed' => $this->auditCount('institution_member_role_changed'),
            'institutions' => (int) $this->scalar('SELECT COUNT(*) FROM institutions'),
            'memberships' => (int) $this->scalar('SELECT COUNT(*) FROM institution_memberships'),
        ];
    }

    private function applicationCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM teacher_applications');
    }

    private function applicationStatus(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM teacher_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
    }

    private function auditCount(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', [$action]);
    }

    private function latestRoleReason(): string
    {
        $raw = $this->scalar(
            'SELECT metadata FROM security_audit_events WHERE action = ? ORDER BY occurred_at DESC, id DESC LIMIT 1',
            ['role_changed'],
        );
        $decoded = json_decode((string) $raw, true);
        $reason = \is_array($decoded) ? ($decoded['reason'] ?? null) : null;

        return \is_string($reason) ? $reason : '';
    }

    /**
     * @return list<string>
     */
    private function roles(string $email): array
    {
        $roles = $this->withKernel(fn (): array => $this->user($email)->getRoles());

        return array_values(array_filter($roles, static fn (mixed $role): bool => \is_string($role)));
    }

    private function userId(string $email): string
    {
        $id = $this->withKernel(fn (): string => $this->user($email)->getId()->toRfc4122());

        return \is_string($id) ? $id : '';
    }

    private function setStatus(string $email, UserStatus $status): void
    {
        $this->withKernel(static function () use ($email, $status): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement(
                'UPDATE users SET status = ? WHERE email = ?',
                [$status->value, $email],
            );
        });
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params = []): int|string
    {
        $value = $this->withKernel(static function () use ($sql, $params): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchOne($sql, $params);
        });

        return \is_int($value) || \is_string($value) ? $value : '';
    }

    private function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function withKernel(callable $callback): mixed
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            return $callback();
        } finally {
            self::ensureKernelShutdown();
        }
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function csrf(KernelBrowser $client, string $intention): string
    {
        $session = $client->getRequest()->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($client->getRequest());
        try {
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);
            $value = $tokens->getToken($intention)->getValue();
            $session->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }
}
