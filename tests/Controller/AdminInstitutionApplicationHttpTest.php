<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\InstitutionApplicationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Service\UserStatusManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminInstitutionApplicationHttpTest extends WebTestCase
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

    public function testSuperAdminDecisionChangesOnlyTheSelectedApplication(): void
    {
        $client = $this->browser();
        $this->login($client, 'instq-sa@example.com');
        $before = $this->privilegeSnapshot();
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame(1, $this->auditCount('institution_application_approved'));
        self::assertSame(0, $this->auditCount('institution_application_rejected'));
        self::assertSame($before, $this->privilegeSnapshot());
        self::assertNotContains(UserRole::InstitutionManager->value, $this->roles('instq-a@example.com'));

        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingB);
        $client->submit($client->getCrawler()->selectButton('Reddet')->form([
            'reason_code' => 'incomplete_documents',
        ]));
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Rejected->value, $this->applicationStatus($this->pendingB));
        self::assertSame('incomplete_documents', $this->reasonCode($this->pendingB));
        self::assertSame(1, $this->auditCount('institution_application_approved'));
        self::assertSame(1, $this->auditCount('institution_application_rejected'));
        self::assertSame($before, $this->privilegeSnapshot());
        self::assertNotContains(UserRole::InstitutionManager->value, $this->roles('instq-b@example.com'));
    }

    public function testSecondDecisionIsRejectedByTerminalStatus(): void
    {
        $client = $this->browser();
        $this->login($client, 'instq-sa@example.com');
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();
        $before = $this->privilegeSnapshot();
        $approvedAudits = $this->auditCount('institution_application_approved');

        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        $token = $this->csrf($client, 'institution_application_approve');
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/onayla', ['_token' => $token]);
        self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$this->pendingA);
        $client->followRedirect();

        self::assertStringContainsString('Başvuru durumu bu işlem için uygun değil.', (string) $client->getResponse()->getContent());
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame($approvedAudits, $this->auditCount('institution_application_approved'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testAdminTeacherApplicantAndSuspendedSuperAdminCannotDecide(): void
    {
        $this->assertDecisionDenied('instq-admin@example.com');
        $this->assertDecisionDenied('instq-teacher@example.com');
        $this->assertDecisionDenied('instq-a@example.com');

        $client = $this->browser();
        $this->login($client, 'instq-sa@example.com');
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
        $approve = $this->csrf($client, 'institution_application_approve');
        $reject = $this->csrf($client, 'institution_application_reject');
        $this->setStatus('instq-sa@example.com', UserStatus::Suspended);
        $before = $this->decisionSnapshot();

        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/onayla', [
            '_token' => $approve,
        ]);
        self::assertResponseRedirects('/giris');
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingB.'/reddet', [
            '_token' => $reject,
            'reason_code' => 'incomplete_documents',
        ]);
        self::assertResponseRedirects('/giris');
        self::assertSame($before, $this->decisionSnapshot());
    }

    public function testBrokenOrMissingReferenceDoesNotChangeAnotherApplication(): void
    {
        $client = $this->browser();
        $this->login($client, 'instq-sa@example.com');
        $client->request('GET', '/yonetim/kurum-basvurulari');
        $token = $this->csrf($client, 'institution_application_approve');
        $before = $this->privilegeSnapshot();
        $missing = Uuid::v7()->toRfc4122();

        $client->request('GET', '/yonetim/kurum-basvurulari/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/kurum-basvurulari/not-a-uuid/onayla', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$missing);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$missing.'/onayla', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame(0, $this->auditCount('institution_application_approved'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testInvalidCsrfAndRejectReasonWriteNothing(): void
    {
        $client = $this->browser();
        $this->login($client, 'instq-sa@example.com');
        $before = $this->privilegeSnapshot();

        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/onayla', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingB.'/reddet', [
            '_token' => 'invalid',
            'reason_code' => 'incomplete_documents',
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingB);
        $token = $this->csrf($client, 'institution_application_reject');
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingB.'/reddet', [
            '_token' => $token,
            'reason_code' => '1bad',
        ]);
        self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$this->pendingB);

        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame(0, $this->auditCount('institution_application_approved'));
        self::assertSame(0, $this->auditCount('institution_application_rejected'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testGetWritesNothing(): void
    {
        $client = $this->browser();
        $this->login($client, 'instq-sa@example.com');
        $before = $this->privilegeSnapshot();
        $applications = $this->applicationCount();
        $audits = $this->auditCount('institution_application_submitted');

        $client->request('GET', '/yonetim/kurum-basvurulari');
        self::assertResponseIsSuccessful();
        $list = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Başvuru onayı kurum, üyelik veya rol vermez.', $list);
        self::assertStringContainsString('Ada &lt;b&gt;Okulu', $list);
        self::assertStringNotContainsString('<b>Okulu', $list);
        self::assertStringContainsString('Okul', $list);
        self::assertStringContainsString('Kurs merkezi', $list);
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        self::assertResponseIsSuccessful();
        $detail = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Başvuru onayı kurum, üyelik veya rol vermez.', $detail);
        self::assertStringContainsString('instq-a@example.com', $detail);
        self::assertStringContainsString('Ada &lt;b&gt;Okulu', $detail);
        self::assertStringNotContainsString('<b>Okulu', $detail);

        self::assertSame($applications, $this->applicationCount());
        self::assertSame($audits, $this->auditCount('institution_application_submitted'));
        self::assertSame(0, $this->auditCount('institution_application_approved'));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testPendingListQueryCountDoesNotGrow(): void
    {
        $client = $this->browser();
        $client->disableReboot();
        $this->login($client, 'instq-sa@example.com');
        $client->request('GET', '/yonetim/kurum-basvurulari');
        self::assertResponseIsSuccessful();
        $small = $this->listQueryCount($client);
        $this->addPendingApplications($client, 4);
        $large = $this->listQueryCount($client);

        self::assertSame(1, $small['applications']);
        self::assertSame(1, $large['applications']);
        self::assertSame($small['users'], $large['users']);
        self::assertGreaterThan(0, $small['applications'] + $small['users']);
    }

    public function testSuspendedApplicantDecisionStaysOnTheExistingContract(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('instq-held@example.com', UserRole::Student, 'Duru', 'Kaya');
            $applications = static::getContainer()->get(InstitutionApplicationManager::class);
            $status = static::getContainer()->get(UserStatusManager::class);
            self::assertInstanceOf(InstitutionApplicationManager::class, $applications);
            self::assertInstanceOf(UserStatusManager::class, $status);
            $applications->submit($this->user('instq-held@example.com'), 'Duru Okulu', InstitutionType::School);
            $status->suspend($this->user('instq-held@example.com'), $this->user('instq-sa@example.com'), 'review_hold');
        });
        $id = $this->applicationIdFor('instq-held@example.com');
        $client = $this->browser();
        $this->login($client, 'instq-sa@example.com');
        $before = $this->privilegeSnapshot();
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$id);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($id));
        self::assertNotContains(UserRole::InstitutionManager->value, $this->roles('instq-held@example.com'));
        self::assertSame($before, $this->privilegeSnapshot());
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
    }

    private function assertDecisionDenied(string $email): void
    {
        $client = $this->browser();
        $this->login($client, $email);
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
        $approve = $this->csrf($client, 'institution_application_approve');
        $reject = $this->csrf($client, 'institution_application_reject');
        $before = $this->decisionSnapshot();

        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/onayla', [
            '_token' => $approve,
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingB.'/reddet', [
            '_token' => $reject,
            'reason_code' => 'incomplete_documents',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->decisionSnapshot());
    }

    /**
     * @return array{
     *     statuses: array<string, string>,
     *     approved_audits: int,
     *     rejected_audits: int,
     *     roles: array<string, list<string>>,
     *     privileges: array<string, int>
     * }
     */
    private function decisionSnapshot(): array
    {
        return [
            'statuses' => [
                $this->pendingA => $this->applicationStatus($this->pendingA),
                $this->pendingB => $this->applicationStatus($this->pendingB),
            ],
            'approved_audits' => $this->auditCount('institution_application_approved'),
            'rejected_audits' => $this->auditCount('institution_application_rejected'),
            'roles' => [
                'instq-sa@example.com' => $this->roles('instq-sa@example.com'),
                'instq-admin@example.com' => $this->roles('instq-admin@example.com'),
                'instq-teacher@example.com' => $this->roles('instq-teacher@example.com'),
                'instq-a@example.com' => $this->roles('instq-a@example.com'),
                'instq-b@example.com' => $this->roles('instq-b@example.com'),
            ],
            'privileges' => $this->privilegeSnapshot(),
        ];
    }

    private function seed(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('instq-sa@example.com', UserRole::SuperAdmin, 'Selin', 'Aydın');
            $this->createActive('instq-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
            $this->createActive('instq-teacher@example.com', UserRole::Teacher, 'Ece', 'Koç');
            $this->createActive('instq-a@example.com', UserRole::Student, 'Ada', 'Yılmaz');
            $this->createActive('instq-b@example.com', UserRole::Student, 'Bora', 'Yılmaz');
            $applications = static::getContainer()->get(InstitutionApplicationManager::class);
            self::assertInstanceOf(InstitutionApplicationManager::class, $applications);
            $this->pendingA = $applications->submit($this->user('instq-a@example.com'), 'Ada <b>Okulu', InstitutionType::School)->getId()->toRfc4122();
            $this->pendingB = $applications->submit($this->user('instq-b@example.com'), 'Bora Kurs', InstitutionType::CourseCenter)->getId()->toRfc4122();
        });
    }

    private function addPendingApplications(KernelBrowser $client, int $count): void
    {
        $applications = $client->getContainer()->get(InstitutionApplicationManager::class);
        $factory = $client->getContainer()->get(UserFactory::class);
        $lifecycle = $client->getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(InstitutionApplicationManager::class, $applications);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        for ($index = 1; $index <= $count; ++$index) {
            $email = 'instq-extra-'.$index.'@example.com';
            $user = $factory->createAndPersist($email, self::PASSWORD, 'Ek', 'Basvuran', UserRole::Student);
            if (UserStatus::PendingVerification === $user->getStatus()) {
                $lifecycle->markEmailVerifiedAndActivate($user);
            }
            $applications->submit($user, 'Ek Okul '.$index, InstitutionType::TutoringCenter);
        }
    }

    /**
     * @return array{applications: int, users: int}
     */
    private function listQueryCount(KernelBrowser $client): array
    {
        $holder = $client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $client->request('GET', '/yonetim/kurum-basvurulari');
        self::assertResponseIsSuccessful();
        $applications = 0;
        $users = 0;
        $joined = false;
        foreach ($holder->getData() as $queries) {
            if (!\is_array($queries)) {
                continue;
            }
            foreach ($queries as $item) {
                $statement = \is_array($item) ? ($item['sql'] ?? null) : null;
                if (!\is_string($statement) || '' === trim($statement)) {
                    continue;
                }
                if (str_contains($statement, 'institution_applications')) {
                    ++$applications;
                    $joined = $joined || str_contains($statement, 'users');
                    continue;
                }
                if (str_contains($statement, 'users')) {
                    ++$users;
                }
            }
        }
        self::assertTrue($joined);

        return ['applications' => $applications, 'users' => $users];
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
            $ids = $em->getConnection()->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'instq-%'");
            if ([] === $ids) {
                return;
            }
            $em->getConnection()->executeStatement('DELETE FROM institution_applications WHERE user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM security_audit_events WHERE actor_user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM users WHERE email LIKE ?', ['instq-%']);
        });
    }

    /**
     * @return array<string, int>
     */
    private function privilegeSnapshot(): array
    {
        return [
            'role_changed' => $this->auditCount('role_changed'),
            'institution_created' => $this->auditCount('institution_created'),
            'institution_member_added' => $this->auditCount('institution_member_added'),
            'institution_member_role_changed' => $this->auditCount('institution_member_role_changed'),
            'institutions' => (int) $this->scalar('SELECT COUNT(*) FROM institutions'),
            'memberships' => (int) $this->scalar('SELECT COUNT(*) FROM institution_memberships'),
        ];
    }

    private function applicationCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM institution_applications');
    }

    private function applicationStatus(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM institution_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
    }

    private function reasonCode(string $id): string
    {
        return (string) $this->scalar('SELECT decision_reason_code FROM institution_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
    }

    private function applicationIdFor(string $email): string
    {
        $id = $this->withKernel(function () use ($email): string {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return (string) $em->getConnection()->fetchOne(
                'SELECT id FROM institution_applications WHERE user_id = ?',
                [$this->user($email)->getId()->toBinary()],
            );
        });

        return Uuid::fromBinary($id)->toRfc4122();
    }

    private function auditCount(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', [$action]);
    }

    /**
     * @return list<string>
     */
    private function roles(string $email): array
    {
        $roles = $this->withKernel(fn (): array => $this->user($email)->getRoles());

        return array_values(array_filter($roles, static fn (mixed $role): bool => \is_string($role)));
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
