<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
        $this->loginAs($client, 'admin_matrix_admin@example.com', UserRole::Admin);

        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorTextContains('body', 'Sınırlı görünüm');
        self::assertSelectorNotExists('a[href="/yonetim/odemeler"]');
        self::assertSelectorNotExists('a[href="/yonetim/denetim"]');

        $client->request('GET', '/yonetim/sistem');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sistem durumu');

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
        self::assertSelectorExists('a[href="/yonetim/odemeler"]');
        self::assertSelectorExists('a[href="/yonetim/denetim"]');
        self::assertSelectorExists('a[href="/yonetim/uzlastirma"]');
        self::assertSelectorExists('.bottom-nav a[href="/yonetim/uzlastirma"]');
        self::assertSelectorExists('.bottom-nav a[href="/yonetim/denetim"]');

        foreach ([
            '/yonetim/odemeler',
            '/yonetim/webhook',
            '/yonetim/uzlastirma',
            '/yonetim/denetim',
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful(\sprintf('%s should be allowed for SA', $path));
            self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        }
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
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): User
    {
        $user = $this->createPrivilegedUser($email, $role);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();

        return $user;
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
