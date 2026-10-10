<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\TeacherApplicationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Service\UserStatusManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminTeacherApplicationHttpTest extends WebTestCase
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
        $this->login($client, 'appq-sa@example.com');
        $before = $this->privilegeSnapshot();
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame(1, $this->auditCount('teacher_application_approved'));
        self::assertSame(0, $this->auditCount('teacher_application_rejected'));
        self::assertSame($before, $this->privilegeSnapshot());
        self::assertNotContains(UserRole::Teacher->value, $this->roles('appq-a@example.com'));

        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingB);
        $client->submit($client->getCrawler()->selectButton('Reddet')->form([
            'reason_code' => 'incomplete_documents',
        ]));
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Rejected->value, $this->applicationStatus($this->pendingB));
        self::assertSame('incomplete_documents', $this->reasonCode($this->pendingB));
        self::assertSame(1, $this->auditCount('teacher_application_approved'));
        self::assertSame(1, $this->auditCount('teacher_application_rejected'));
        self::assertSame($before, $this->privilegeSnapshot());
        self::assertNotContains(UserRole::Teacher->value, $this->roles('appq-b@example.com'));
    }

    public function testSecondDecisionIsRejectedByTerminalStatus(): void
    {
        $client = $this->browser();
        $this->login($client, 'appq-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();
        $before = $this->privilegeSnapshot();
        $approvedAudits = $this->auditCount('teacher_application_approved');

        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $token = $this->csrf($client, 'teacher_application_approve');
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/onayla', ['_token' => $token]);
        self::assertResponseRedirects('/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->followRedirect();

        self::assertStringContainsString('Başvuru durumu bu işlem için uygun değil.', (string) $client->getResponse()->getContent());
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame($approvedAudits, $this->auditCount('teacher_application_approved'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testAdminTeacherApplicantAndSuspendedSuperAdminCannotDecide(): void
    {
        $this->assertDecisionDenied('appq-admin@example.com');
        $this->assertDecisionDenied('appq-teacher@example.com');
        $this->assertDecisionDenied('appq-a@example.com');

        $client = $this->browser();
        $this->login($client, 'appq-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $token = $this->csrf($client, 'teacher_application_approve');
        $this->setStatus('appq-sa@example.com', UserStatus::Suspended);
        $before = $this->privilegeSnapshot();
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/onayla', ['_token' => $token]);

        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame(0, $this->auditCount('teacher_application_approved'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testBrokenOrMissingReferenceDoesNotChangeAnotherApplication(): void
    {
        $client = $this->browser();
        $this->login($client, 'appq-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari');
        $token = $this->csrf($client, 'teacher_application_approve');
        $before = $this->privilegeSnapshot();
        $missing = Uuid::v7()->toRfc4122();

        $client->request('GET', '/yonetim/ogretmen-basvurulari/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/ogretmen-basvurulari/not-a-uuid/onayla', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$missing);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$missing.'/onayla', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame(0, $this->auditCount('teacher_application_approved'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testInvalidCsrfAndRejectReasonWriteNothing(): void
    {
        $client = $this->browser();
        $this->login($client, 'appq-sa@example.com');
        $before = $this->privilegeSnapshot();

        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/onayla', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingB.'/reddet', [
            '_token' => 'invalid',
            'reason_code' => 'incomplete_documents',
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingB);
        $token = $this->csrf($client, 'teacher_application_reject');
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingB.'/reddet', [
            '_token' => $token,
            'reason_code' => '1bad',
        ]);
        self::assertResponseRedirects('/yonetim/ogretmen-basvurulari/'.$this->pendingB);

        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertSame(0, $this->auditCount('teacher_application_approved'));
        self::assertSame(0, $this->auditCount('teacher_application_rejected'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testGetWritesNothing(): void
    {
        $client = $this->browser();
        $this->login($client, 'appq-sa@example.com');
        $before = $this->privilegeSnapshot();
        $applications = $this->applicationCount();
        $audits = $this->auditCount('teacher_application_submitted');

        $client->request('GET', '/yonetim/ogretmen-basvurulari');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Başvuru onayı öğretmen rolü vermez', (string) $client->getResponse()->getContent());
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Başvuru onayı öğretmen rolü vermez', (string) $client->getResponse()->getContent());

        self::assertSame($applications, $this->applicationCount());
        self::assertSame($audits, $this->auditCount('teacher_application_submitted'));
        self::assertSame(0, $this->auditCount('teacher_application_approved'));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    public function testSuspendedApplicantDecisionStaysOnTheExistingContract(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('appq-held@example.com', UserRole::Student, 'Duru', 'Kaya');
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            $status = static::getContainer()->get(UserStatusManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            self::assertInstanceOf(UserStatusManager::class, $status);
            $applications->submit($this->user('appq-held@example.com'));
            $status->suspend($this->user('appq-held@example.com'), $this->user('appq-sa@example.com'), 'review_hold');
        });
        $id = $this->applicationIdFor('appq-held@example.com');
        $client = $this->browser();
        $this->login($client, 'appq-sa@example.com');
        $before = $this->privilegeSnapshot();
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$id);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($id));
        self::assertNotContains(UserRole::Teacher->value, $this->roles('appq-held@example.com'));
        self::assertSame($before, $this->privilegeSnapshot());
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
    }

    private function assertDecisionDenied(string $email): void
    {
        $client = $this->browser();
        $this->login($client, $email);
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $token = '';
        if (200 === $client->getResponse()->getStatusCode()) {
            $token = $this->csrf($client, 'teacher_application_approve');
        }
        $before = $this->privilegeSnapshot();
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/onayla', [
            '_token' => '' !== $token ? $token : 'invalid',
        ]);

        self::assertNotSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame(0, $this->auditCount('teacher_application_approved'));
        self::assertSame($before, $this->privilegeSnapshot());
    }

    private function seed(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('appq-sa@example.com', UserRole::SuperAdmin, 'Selin', 'Aydın');
            $this->createActive('appq-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
            $this->createActive('appq-teacher@example.com', UserRole::Teacher, 'Ece', 'Koç');
            $this->createActive('appq-a@example.com', UserRole::Student, 'Ada', 'Yılmaz');
            $this->createActive('appq-b@example.com', UserRole::Student, 'Bora', 'Yılmaz');
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            $this->pendingA = $applications->submit($this->user('appq-a@example.com'))->getId()->toRfc4122();
            $this->pendingB = $applications->submit($this->user('appq-b@example.com'))->getId()->toRfc4122();
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
            $ids = $em->getConnection()->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'appq-%'");
            if ([] === $ids) {
                return;
            }
            $em->getConnection()->executeStatement('DELETE FROM teacher_applications WHERE user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM security_audit_events WHERE actor_user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM users WHERE email LIKE ?', ['appq-%']);
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
        return (int) $this->scalar('SELECT COUNT(*) FROM teacher_applications');
    }

    private function applicationStatus(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM teacher_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
    }

    private function reasonCode(string $id): string
    {
        return (string) $this->scalar('SELECT decision_reason_code FROM teacher_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
    }

    private function applicationIdFor(string $email): string
    {
        $id = $this->withKernel(function () use ($email): string {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return (string) $em->getConnection()->fetchOne(
                'SELECT id FROM teacher_applications WHERE user_id = ?',
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
