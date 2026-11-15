<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Admin\AdminSystemHealthReadModel;
use App\Service\HealthChecker;
use App\Service\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Visual/security contracts for GET /yonetim/sistem (no controller/domain refactor).
 */
final class AdminSystemControllerTest extends WebTestCase
{
    public function testAdminSeesHealthWithoutWebhookQueue(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_system_admin@example.com', UserRole::Admin);

        $client->request('GET', '/yonetim/sistem');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.admin-system-page');
        self::assertSelectorTextContains('#admin-system-heading', 'Sistem durumu');
        self::assertSelectorExists('#admin-system-health-heading');
        self::assertSelectorTextContains('.admin-system-page__facts', 'Uygulama');
        self::assertSelectorTextContains('.admin-system-page__facts', 'Veritabanı');
        self::assertSelectorTextContains('.admin-system-page__facts', 'Redis');
        self::assertSelectorTextContains('.admin-system-page__facts', 'Migrasyon');
        self::assertSelectorExists('.admin-status');
        self::assertSelectorNotExists('#admin-system-webhook-heading');
        self::assertSelectorTextNotContains('body', 'Webhook kuyruğu');
        self::assertSelectorNotExists('.admin-system-page form');
        self::assertSelectorNotExists('.admin-system-page button[type="submit"]');
        $this->assertNoSensitiveLeak((string) $client->getResponse()->getContent());
    }

    public function testSuperAdminSeesWebhookQueueSummary(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_system_sa@example.com', UserRole::SuperAdmin);

        $client->request('GET', '/yonetim/sistem');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#admin-system-webhook-heading');
        self::assertSelectorTextContains('body', 'Webhook kuyruğu');
        self::assertSelectorTextContains('body', 'Zamanı gelen');
        self::assertSelectorTextContains('body', 'Teslim edilemeyen');
        $this->assertNoSensitiveLeak((string) $client->getResponse()->getContent());
    }

    public function testModeratorForbidden(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_system_mod@example.com', UserRole::Moderator);
        $client->request('GET', '/yonetim/sistem');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStudentForbidden(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_system_student@example.com', UserRole::Student);
        $client->request('GET', '/yonetim/sistem');
        self::assertResponseStatusCodeSame(403);
    }

    public function testGetDoesNotWriteAuditOrWebhookInbox(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'admin_system_nowrite@example.com', UserRole::Admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $before = $this->writeFingerprint($em);

        $client->request('GET', '/yonetim/sistem');
        self::assertResponseIsSuccessful();

        $emAfter = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emAfter);
        self::assertSame($before, $this->writeFingerprint($emAfter));
    }

    public function testDegradedProbeRendersWarningAndUnavailableLabels(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->loginAs($client, 'admin_system_degraded@example.com', UserRole::Admin);
        $this->replaceHealthChecker(
            $client,
            $this->buildHealthChecker($client, databaseOk: true, redisUrl: 'redis://127.0.0.1:9'),
        );

        $crawler = $client->request('GET', '/yonetim/sistem');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-system-page', 'Uyarı');
        self::assertSelectorTextContains('.admin-system-page', 'Çalışıyor');
        self::assertSelectorTextContains('.admin-system-page', 'Kullanılamıyor');
        self::assertGreaterThan(0, $crawler->filter('.admin-status--warning')->count());
        self::assertGreaterThan(0, $crawler->filter('.admin-status--danger')->count());
        $this->assertNoSensitiveLeak((string) $client->getResponse()->getContent());
    }

    public function testUnavailableDatabaseRendersUnavailableApplication(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->loginAs($client, 'admin_system_unavailable@example.com', UserRole::Admin);
        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willThrowException(new \RuntimeException('db_probe_fail'));
        $this->replaceHealthChecker(
            $client,
            $this->buildHealthChecker($client, databaseOk: false, redisUrl: 'redis://127.0.0.1:6379', connection: $failingConnection),
        );

        $client->request('GET', '/yonetim/sistem');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-system-page__facts', 'Kullanılamıyor');
        self::assertSelectorExists('.admin-status--danger');
        $this->assertNoSensitiveLeak((string) $client->getResponse()->getContent());
    }

    public function testTemplateMapsAllKnownStatusLabelsIncludingMigrationNotCurrent(): void
    {
        $template = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/admin/system.html.twig');
        self::assertStringContainsString("'Çalışıyor': 'success'", $template);
        self::assertStringContainsString("'Uyarı': 'warning'", $template);
        self::assertStringContainsString("'Kullanılamıyor': 'danger'", $template);
        self::assertStringContainsString("'Güncel': 'success'", $template);
        self::assertStringContainsString("'Güncel değil': 'warning'", $template);
        self::assertStringContainsString('admin-status--{{ status_variant[system.migrationStatus]', $template);
        self::assertStringNotContainsString('|raw', $template);
    }

    private function buildHealthChecker(
        KernelBrowser $client,
        bool $databaseOk,
        string $redisUrl,
        ?Connection $connection = null,
    ): HealthChecker {
        if ($databaseOk) {
            $connection = $client->getContainer()->get(Connection::class);
            self::assertInstanceOf(Connection::class, $connection);
        } else {
            self::assertInstanceOf(Connection::class, $connection);
        }
        $logger = $client->getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(LoggerInterface::class, $logger);

        return new HealthChecker($connection, 'test', $redisUrl, $logger);
    }

    private function replaceHealthChecker(KernelBrowser $client, HealthChecker $checker): void
    {
        $original = $client->getContainer()->get(AdminSystemHealthReadModel::class);
        self::assertInstanceOf(AdminSystemHealthReadModel::class, $original);

        // Readonly constructor props cannot be overwritten on a live instance; rebuild
        // an uninitialized ReadModel and copy dependencies, swapping only HealthChecker.
        $reflection = new \ReflectionClass(AdminSystemHealthReadModel::class);
        $replacement = $reflection->newInstanceWithoutConstructor();
        foreach ($reflection->getProperties() as $property) {
            $value = 'healthChecker' === $property->getName()
                ? $checker
                : $property->getValue($original);
            $property->setValue($replacement, $value);
        }
        $client->getContainer()->set(AdminSystemHealthReadModel::class, $replacement);
    }

    /**
     * @return array{audit: int, webhook_inbox: int|null}
     */
    private function writeFingerprint(EntityManagerInterface $em): array
    {
        $connection = $em->getConnection();
        $schema = $connection->createSchemaManager();
        $webhook = null;
        if ($schema->tablesExist(['payment_webhook_inbox_events'])) {
            $webhook = (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_webhook_inbox_events');
        }

        return [
            'audit' => (int) $connection->fetchOne('SELECT COUNT(*) FROM security_audit_events'),
            'webhook_inbox' => $webhook,
        ];
    }

    private function assertNoSensitiveLeak(string $html): void
    {
        self::assertStringNotContainsString('DATABASE_URL', $html);
        self::assertStringNotContainsString('REDIS_URL', $html);
        self::assertStringNotContainsString('mysql://', $html);
        self::assertStringNotContainsString('redis://', $html);
        self::assertStringNotContainsString('system_health_canary_secret', $html);
        self::assertStringNotContainsString('PDOException', $html);
        self::assertStringNotContainsString('Traceback', $html);
        self::assertStringNotContainsString('exception_class', $html);
        $template = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/admin/system.html.twig');
        self::assertStringNotContainsString('|raw', $template);
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): User
    {
        $user = $this->createUser($email, $role);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();

        return $user;
    }

    private function createUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $existing = $users->findOneByNormalizedEmail(mb_strtolower($email));
        if ($existing instanceof User) {
            self::ensureKernelShutdown();

            return $existing;
        }
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Sys', 'Admin', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
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
                    "DELETE FROM users WHERE normalized_email LIKE 'admin_system_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
