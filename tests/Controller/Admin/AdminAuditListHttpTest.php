<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\SecurityAuditEvent;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

final class AdminAuditListHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';
    private const LONG_REASON = 'safe_reason_code_wrap_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    private const CANARY_TOKEN = 'audit_list_canary_token_do_not_leak';

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        $this->disableProbe();
        try {
            if (static::$booted) {
                $em = static::getContainer()->get(EntityManagerInterface::class);
                self::assertInstanceOf(EntityManagerInterface::class, $em);
                $em->getConnection()->executeStatement(
                    "DELETE FROM security_audit_events WHERE metadata LIKE '%audit_list_http%'",
                );
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'audit_list_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function testAnonymousRedirectsToLogin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/yonetim/denetim');
        self::assertResponseRedirects('/giris');
    }

    public function testAdminModeratorTeacherForbidden(): void
    {
        foreach ([
            [UserRole::Admin, 'audit_list_admin@example.com'],
            [UserRole::Moderator, 'audit_list_moderator@example.com'],
            [UserRole::Teacher, 'audit_list_teacher@example.com'],
        ] as [$role, $email]) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->loginHttp($client, $email, $role);
            $client->request('GET', '/yonetim/denetim');
            self::assertResponseStatusCodeSame(403, $email.' should be forbidden');
        }
    }

    public function testUnverifiedAndSuspendedSuperAdminForbidden(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->loginHttp($client, 'audit_list_unverified_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );
        $client->request('GET', '/yonetim/denetim');
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $suspended = $factory->createAndPersist('audit_list_susp_sa@example.com', self::PASSWORD, 'Ad', 'Min', UserRole::Teacher);
        $suspended->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $suspended->transitionTo(UserStatus::Active);
        $suspended->addGlobalRole(UserRole::SuperAdmin);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($suspended);
        $suspended->transitionTo(UserStatus::Suspended);
        $users->save($suspended);
        $client->loginUser($suspended);
        $client->request('GET', '/yonetim/denetim');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyListCopyAfterClearingEvents(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'audit_list_empty_sa@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement('DELETE FROM security_audit_events');

        $client->request('GET', '/yonetim/denetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Güvenlik denetim olayları');
        self::assertSelectorTextContains('body', 'Sistem güvenlik olaylarının salt okunur kaydı');
        self::assertSelectorTextContains('body', 'Bu ekrandan olay oluşturulmaz veya değiştirilmez');
        self::assertSelectorExists('#panel-sidebar a[href="/yonetim/denetim"][aria-current="page"]');
        self::assertSelectorTextContains('body', 'Olay yok');
        self::assertSelectorTextContains('body', 'Gösterilecek denetim kaydı bulunamadı.');
        self::assertSelectorNotExists('#audit-list-heading');
        self::assertSelectorNotExists('form[action="/yonetim/calisma-kimligi"]');
    }

    public function testMixedListTurkishLabelsPaginationSortingSanitizerAndNoWrite(): void
    {
        // Keep login audit older than fixture rows so occurredAt DESC still surfaces fixtures first.
        Clock::set(new MockClock(new \DateTimeImmutable('2026-09-13 11:00:00', new \DateTimeZone('UTC'))));
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $base = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));
        $oldest = $this->persistEvent(
            $em,
            SecurityAuditAction::LoginSucceeded,
            SecurityAuditActorType::User,
            SecurityAuditOutcome::Success,
            $base,
            ['source' => 'audit_list_http', 'reason_code' => 'login_ok'],
        );
        $failed = $this->persistEvent(
            $em,
            SecurityAuditAction::PaymentFailed,
            SecurityAuditActorType::System,
            SecurityAuditOutcome::Failure,
            $base->modify('+1 minute'),
            ['source' => 'audit_list_http', 'failure_code' => 'provider_declined'],
        );
        $cli = $this->persistEvent(
            $em,
            SecurityAuditAction::RoleChanged,
            SecurityAuditActorType::Cli,
            SecurityAuditOutcome::Success,
            $base->modify('+2 minutes'),
            ['source' => 'audit_list_http', 'previous_roles' => 'ROLE_TEACHER', 'new_roles' => 'ROLE_ADMIN'],
        );
        $long = $this->persistEvent(
            $em,
            SecurityAuditAction::PaymentReconciliationDiscrepancyFound,
            SecurityAuditActorType::System,
            SecurityAuditOutcome::Failure,
            $base->modify('+3 minutes'),
            ['source' => 'audit_list_http', 'reason_code' => self::LONG_REASON, 'reconciliation_run_id' => 'run-long'],
            ipHash: 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            userAgentHash: 'cafebabecafebabecafebabecafebabecafebabecafebabecafebabecafebabe',
        );
        // Forbidden key stored raw; list path must re-sanitize to empty metadata (no canary leak).
        $toxic = $this->persistEvent(
            $em,
            SecurityAuditAction::StatusChanged,
            SecurityAuditActorType::User,
            SecurityAuditOutcome::Success,
            $base->modify('+4 minutes'),
            ['source' => 'audit_list_http', 'token' => self::CANARY_TOKEN, 'email' => 'leak@example.com'],
        );
        $newest = $this->persistEvent(
            $em,
            SecurityAuditAction::HomeworkEvaluationRecorded,
            SecurityAuditActorType::User,
            SecurityAuditOutcome::Success,
            $base->modify('+5 minutes'),
            ['source' => 'audit_list_http', 'label' => 'reviewed'],
        );

        $fixtureIds = [
            $oldest->getId(),
            $failed->getId(),
            $cli->getId(),
            $long->getId(),
            $toxic->getId(),
            $newest->getId(),
        ];
        $fingerprintBefore = $this->fingerprintEvents($em, $fixtureIds);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginHttp($client, 'audit_list_mix_sa@example.com', UserRole::SuperAdmin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        // Login may append other audit rows; fixture ids must stay byte-identical.
        self::assertSame($fingerprintBefore, $this->fingerprintEvents($em, $fixtureIds));

        $client->request('GET', '/yonetim/denetim');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorExists('#audit-list-heading');
        self::assertSelectorTextContains('body', 'Giriş başarılı');
        self::assertSelectorTextContains('body', 'Ödeme başarısız');
        self::assertSelectorTextContains('body', 'Rol değiştirildi');
        self::assertSelectorTextContains('body', 'Ödeme uzlaştırma tutarsızlığı bulundu');
        self::assertSelectorTextContains('body', 'Ödev değerlendirmesi kaydedildi');
        self::assertSelectorTextContains('body', 'Kullanıcı');
        self::assertSelectorTextContains('body', 'Sistem');
        self::assertSelectorTextContains('body', 'CLI');
        self::assertSelectorTextContains('body', 'Başarılı');
        self::assertSelectorTextContains('body', 'Başarısız');
        self::assertSelectorTextContains('body', self::LONG_REASON);
        self::assertSelectorTextContains('body', 'reviewed');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(self::CANARY_TOKEN, $html);
        self::assertStringNotContainsString('leak@example.com', $html);
        self::assertStringNotContainsString('deadbeefdeadbeef', $html);
        self::assertStringNotContainsString('cafebabecafebabe', $html);
        self::assertStringNotContainsString('ip_hash', $html);
        self::assertStringNotContainsString('user_agent_hash', $html);
        self::assertDoesNotMatchRegularExpression('/\blogin_succeeded\b/', $html);
        self::assertDoesNotMatchRegularExpression('/\bpayment_failed\b/', $html);
        self::assertDoesNotMatchRegularExpression('/\bpayment_reconciliation_discrepancy_found\b/', $html);
        self::assertSelectorNotExists('form[action="/yonetim/calisma-kimligi"]');

        $rows = $client->getCrawler()->filter('.admin-audit-table tbody tr');
        self::assertGreaterThanOrEqual(6, $rows->count());
        $firstAction = trim($rows->first()->filter('.admin-audit__action-cell .admin-audit__action')->text());
        self::assertSame('Ödev değerlendirmesi kaydedildi', $firstAction);

        $client->request('GET', '/yonetim/denetim', [
            'page' => 1,
            'page_size' => 2,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', '2/sayfa');
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 1 /');
        $next = $client->getCrawler()->filter('.admin-pagination a:contains("Sonraki")');
        self::assertCount(1, $next);
        $nextHref = (string) $next->attr('href');
        self::assertStringContainsString('page_size=2', $nextHref);
        self::assertStringContainsString('page=2', $nextHref);

        $client->request('GET', $nextHref);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', 'Sayfa 2 /');
        self::assertSelectorExists('.admin-pagination a:contains("Önceki")');

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        self::assertSame($fingerprintBefore, $this->fingerprintEvents($emAfter, $fixtureIds));
    }

    public function testUnknownActionCodeFallsBackSafelyInPresentation(): void
    {
        $labels = new \App\Presentation\SecurityAuditListLabels();
        self::assertSame('not_a_real_audit_action', $labels->action('not_a_real_audit_action'));
        self::assertSame('weird_actor', $labels->actorType('weird_actor'));
        $outcome = $labels->outcome('weird_outcome');
        self::assertSame('Bilinmiyor', $outcome['label']);
        self::assertSame('neutral', $outcome['variant']);

        foreach (SecurityAuditAction::cases() as $case) {
            self::assertNotSame($case->value, $labels->action($case->value), $case->value.' should have a Turkish label');
        }
    }

    public function testIdentityFormHiddenWhenFlagClosedAndVisibleWhenOpen(): void
    {
        $this->disableProbe();
        self::ensureKernelShutdown();
        $closed = static::createClient();
        $this->loginHttp($closed, 'audit_list_identity_closed@example.com', UserRole::SuperAdmin);
        $closed->request('GET', '/yonetim/denetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/yonetim/calisma-kimligi"]');
        self::assertStringNotContainsString('name="nonce"', (string) $closed->getResponse()->getContent());

        $this->enableProbe();
        self::ensureKernelShutdown();
        $open = static::createClient();
        $this->loginHttp($open, 'audit_list_identity_open@example.com', UserRole::SuperAdmin);
        $crawler = $open->request('GET', '/yonetim/denetim');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.admin-audit-list__identity[action="/yonetim/calisma-kimligi"]');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertCount(1, $form->filter('input[name="_token"]'));
        self::assertCount(1, $form->filter('input[name="nonce"]'));
        $nonce = (string) $form->filter('input[name="nonce"]')->attr('value');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16,64}$/', $nonce);
        self::assertSelectorTextContains('form.admin-audit-list__identity button[type="submit"]', 'Çalışma kimliğini ölç');
        // Presentation-only: do not submit the diagnostic form here.
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    private function persistEvent(
        EntityManagerInterface $em,
        SecurityAuditAction $action,
        SecurityAuditActorType $actorType,
        SecurityAuditOutcome $outcome,
        \DateTimeImmutable $occurredAt,
        array $metadata,
        ?string $ipHash = null,
        ?string $userAgentHash = null,
    ): SecurityAuditEvent {
        $event = SecurityAuditEvent::create(
            action: $action,
            actorType: $actorType,
            outcome: $outcome,
            occurredAt: $occurredAt,
            metadata: $metadata,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
        );
        $em->persist($event);
        $em->flush();

        return $event;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, string>
     */
    private function fingerprintEvents(EntityManagerInterface $em, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $row = $em->getConnection()->fetchAssociative(
                'SELECT action, actor_type, outcome, occurred_at, metadata, ip_hash, user_agent_hash FROM security_audit_events WHERE id = ?',
                [$id->toBinary()],
            );
            self::assertIsArray($row);
            $out[$id->toRfc4122()] = implode('|', [
                (string) $row['action'],
                (string) $row['actor_type'],
                (string) $row['outcome'],
                (string) $row['occurred_at'],
                (string) $row['metadata'],
                (string) ($row['ip_hash'] ?? ''),
                (string) ($row['user_agent_hash'] ?? ''),
            ]);
        }

        return $out;
    }

    private function loginHttp(KernelBrowser $client, string $email, UserRole $role): User
    {
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
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();

        return $user;
    }

    private function enableProbe(): void
    {
        $_SERVER['WEB_RUNTIME_IDENTITY_ENABLED'] = '1';
        $_ENV['WEB_RUNTIME_IDENTITY_ENABLED'] = '1';
        putenv('WEB_RUNTIME_IDENTITY_ENABLED=1');
        $expiresAt = (string) (time() + 300);
        $_SERVER['WEB_RUNTIME_IDENTITY_EXPIRES_AT'] = $expiresAt;
        $_ENV['WEB_RUNTIME_IDENTITY_EXPIRES_AT'] = $expiresAt;
        putenv('WEB_RUNTIME_IDENTITY_EXPIRES_AT='.$expiresAt);
    }

    private function disableProbe(): void
    {
        unset(
            $_SERVER['WEB_RUNTIME_IDENTITY_ENABLED'],
            $_ENV['WEB_RUNTIME_IDENTITY_ENABLED'],
            $_SERVER['WEB_RUNTIME_IDENTITY_EXPIRES_AT'],
            $_ENV['WEB_RUNTIME_IDENTITY_EXPIRES_AT'],
        );
        putenv('WEB_RUNTIME_IDENTITY_ENABLED');
        putenv('WEB_RUNTIME_IDENTITY_EXPIRES_AT');
    }
}
