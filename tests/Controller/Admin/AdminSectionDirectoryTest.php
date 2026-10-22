<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Admin\AdminSectionCatalog;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSectionDirectoryTest extends WebTestCase
{
    public function testSuperAdminCanOpenEveryPlannedSection(): void
    {
        $client = $this->browser();
        $this->login($client, 'section_dir_sa@example.com', UserRole::SuperAdmin);
        $client->disableReboot();
        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#admin-nav-shortcuts', 'Geliştirme aşamasında');
        self::assertSelectorTextContains('#admin-nav-shortcuts', 'Çalışıyor');
        $mediaLinks = $client->getCrawler()->filter('article.admin-directory-card--media a.admin-media-link');
        self::assertCount(3, $mediaLinks);
        self::assertSame('/yonetim/bolum/dokumanlar', $mediaLinks->eq(0)->attr('href'));
        self::assertStringContainsString('PDF ve eğitim materyalleri.', $mediaLinks->eq(0)->text());
        self::assertStringContainsString('Geliştirme aşamasında', $mediaLinks->eq(0)->text());
        self::assertSame('/yonetim/bolum/videolar', $mediaLinks->eq(1)->attr('href'));
        self::assertStringContainsString('Ders ve anlatım videoları.', $mediaLinks->eq(1)->text());
        self::assertSame('/yonetim/bolum/medya', $mediaLinks->eq(2)->attr('href'));
        self::assertStringContainsString('Dosyalar ve depolama alanı.', $mediaLinks->eq(2)->text());
        self::assertSame(0, $client->getCrawler()->filter('article[aria-labelledby="nav-shortcut-learning"] a.admin-media-link')->count());

        foreach (AdminSectionCatalog::all() as $section) {
            $href = '/yonetim/bolum/'.$section['key'];
            self::assertSelectorExists('a[href="'.$href.'"]');
            $client->request('GET', $href);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
            self::assertSelectorTextContains('h1', $section['label']);
            self::assertSelectorTextContains('.admin-stage', 'Geliştirme aşamasında');
            self::assertSelectorTextContains('body', 'Eksik işlemler');
            self::assertSelectorNotExists('button:contains("Kaydet")');
            self::assertSelectorNotExists('button:contains("Oluştur")');
        }

        $client->request('GET', '/yonetim/bolum/yok');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCannotOpenPlannedSectionsAndKeepsWorkingLinks(): void
    {
        $client = $this->browser();
        $this->login($client, 'section_dir_admin@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/yonetim/haberler"]');
        self::assertSelectorExists('a[href="/yonetim/kullanicilar"]');
        self::assertSelectorNotExists('a[href="/yonetim/bolum/subeler"]');
        self::assertSelectorNotExists('a[href="/yonetim/bolum/dokumanlar"]');
        self::assertSelectorNotExists('a[href="/yonetim/bolum/videolar"]');
        self::assertSelectorNotExists('a[href="/yonetim/bolum/medya"]');
        self::assertSelectorNotExists('article.admin-directory-card--media');
        self::assertSelectorNotExists('a[href="/yonetim/odemeler"]');
        $client->request('GET', '/yonetim/bolum/subeler');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/yonetim/haberler');
        self::assertResponseIsSuccessful();
    }

    public function testActiveAdminGroupStaysOpen(): void
    {
        $client = $this->browser();
        $this->login($client, 'section_dir_current@example.com', UserRole::Admin);
        $client->request('GET', '/yonetim/sorular');
        self::assertResponseIsSuccessful();
        self::assertNotNull($client->getCrawler()->filter('#nav-group-learning')->attr('open'));
        self::assertNull($client->getCrawler()->filter('#nav-group-campus')->attr('open'));
        self::assertSelectorExists('#nav-group-learning a[href="/yonetim/sorular"][aria-current="page"]');
        self::assertSelectorNotExists('#nav-group-overview[open]');
    }

    public function testTeacherWorkspaceAndAnonymousStayOutsidePlannedSections(): void
    {
        $teacher = $this->browser();
        $this->login($teacher, 'section_dir_teacher@example.com', UserRole::Teacher);
        $teacher->request('GET', '/yonetim/bolum/simulasyonlar');
        self::assertResponseStatusCodeSame(403);
        $teacher->request('GET', '/calisma-alani');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.panel-nav-group--education');
        self::assertSelectorNotExists('#nav-group-media');
        self::assertSelectorNotExists('a[href="/yonetim/bolum/simulasyonlar"]');

        $anon = $this->browser();
        $anon->request('GET', '/yonetim/bolum/simulasyonlar');
        self::assertResponseRedirects('/giris');
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function login(KernelBrowser $client, string $email, UserRole $role): void
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
        $this->closeDatabase();
        self::ensureKernelShutdown();

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    private function closeDatabase(): void
    {
        if (!static::$booted) {
            return;
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em instanceof EntityManagerInterface && $em->getConnection()->isConnected()) {
            $em->getConnection()->close();
        }
    }

    protected function tearDown(): void
    {
        $this->closeDatabase();
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'section_dir_%@example.com'",
                );
            }
            $this->closeDatabase();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
