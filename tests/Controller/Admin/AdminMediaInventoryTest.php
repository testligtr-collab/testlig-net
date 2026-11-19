<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\StoredMediaAssetRepository;
use App\Repository\UserRepository;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\StoredMediaAssetManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminMediaInventoryTest extends WebTestCase
{
    public function testSuperAdminSeesOnlyPlatformSafeFields(): void
    {
        $this->cleanupMedia();
        $sa = $this->activeUser('media-inv-sa@example.com', UserRole::SuperAdmin);
        $owner = $this->activeUser('media-inv-owner@example.com', UserRole::InstitutionManager);
        $institutions = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $institutions);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $institution = $institutions->create($sa, $owner, 'Medya Kurum Gizli', InstitutionType::School, 'media_inv_inst');
        $status->activate($institution, $sa, 'media_inv_activate');

        $secretFilename = 'gizli-platform-dosya-adi.png';
        $secretHash = str_repeat('ab', 32);
        $secretKeyHint = 'platform/gizli-storage-key';
        $institutionFilename = 'kurum-gizli-medya.pdf';
        $institutionHash = str_repeat('cd', 32);

        $media = $this->media();
        $platform = $media->registerMetadata(
            $sa,
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            $secretFilename,
            'image/png',
            2048,
            $secretHash,
            'reg_platform_inv',
        );
        $media->markScanStatus($platform, $sa, StoredMediaScanStatus::Clean, 'scan_platform_inv');
        $media->markReady($platform, $sa, 'ready_platform_inv');
        $institutionAsset = $media->registerMetadata(
            $owner,
            StoredMediaAssetScope::Institution,
            $institution,
            StoredMediaAssetKind::Document,
            StoredMediaStorageProvider::Local,
            $institutionFilename,
            'application/pdf',
            4096,
            $institutionHash,
            'reg_institution_inv',
        );

        $before = $this->assetCount();
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim/bolum/medya');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorTextContains('h1', 'Medya ve depolama');
        self::assertSelectorNotExists('.admin-stage');
        self::assertSelectorTextContains('body', '1 platform kaydı');
        self::assertSame($before, $this->assetCount(), 'GET must not write media assets');

        $rows = $crawler->filter('.admin-media-inventory-page__table tbody tr');
        self::assertCount(1, $rows);
        self::assertSame(
            ['Tür', 'Boyut', 'Depolama', 'Durum'],
            $rows->filter('.admin-result-field__label')->each(
                static fn (Crawler $cell): string => trim($cell->text()),
            ),
        );
        self::assertSame(
            ['Görsel', '2 KB', 'Yerel'],
            $rows->filter('.admin-result-field__value')->each(
                static fn (Crawler $cell): string => trim($cell->text()),
            ),
        );
        self::assertSame('Hazır', trim($rows->filter('.admin-status')->text()));

        $html = $crawler->html();
        foreach ([
            $secretFilename,
            $secretHash,
            $secretKeyHint,
            $platform->getStorageKey(),
            $institutionFilename,
            $institutionHash,
            $institutionAsset->getStorageKey(),
            $institution->getName(),
            $owner->getEmail(),
            $sa->getEmail(),
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $html);
        }
        self::assertStringNotContainsString('Yükle', $html);
        self::assertStringNotContainsString('Sil', $html);
        self::assertSelectorNotExists('form');
        self::assertSelectorNotExists('button');
    }

    public function testEmptyStateAndAccessGates(): void
    {
        $this->cleanupMedia();
        $sa = $this->activeUser('media-empty-sa@example.com', UserRole::SuperAdmin);
        $client = $this->browser();
        $client->loginUser($sa);
        $client->request('GET', '/yonetim/bolum/medya');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Platform medya kaydı yok');
        self::assertSelectorTextContains('body', '0 platform kaydı');
        self::assertSelectorNotExists('.admin-media-inventory-page__table');

        foreach ([
            ['media-deny-admin@example.com', UserRole::Admin, 403],
            ['media-deny-teacher@example.com', UserRole::Teacher, 403],
        ] as [$email, $role, $status]) {
            $user = $this->activeUser($email, $role);
            $denied = $this->browser();
            $denied->loginUser($user);
            $denied->request('GET', '/yonetim/bolum/medya');
            self::assertResponseStatusCodeSame($status);
        }

        $anon = $this->browser();
        $anon->request('GET', '/yonetim/bolum/medya');
        self::assertResponseRedirects('/giris');
    }

    public function testNavMarksMediaReadyAndCurrentOnce(): void
    {
        $this->cleanupMedia();
        $sa = $this->activeUser('media-nav-sa@example.com', UserRole::SuperAdmin);
        $client = $this->browser();
        $client->loginUser($sa);
        $crawler = $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('a.admin-media-link[href="/yonetim/bolum/medya"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Çalışıyor', $card->text());
        self::assertStringNotContainsString('Geliştirme aşamasında', $card->text());

        $crawler = $client->request('GET', '/yonetim/bolum/medya');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#panel-sidebar a[href="/yonetim/bolum/medya"][aria-current="page"]'));
        self::assertNotNull($crawler->filter('#nav-group-media')->attr('open'));
    }

    private function media(): StoredMediaAssetManager
    {
        $media = static::getContainer()->get(StoredMediaAssetManager::class);
        self::assertInstanceOf(StoredMediaAssetManager::class, $media);

        return $media;
    }

    private function assetCount(): int
    {
        $repo = static::getContainer()->get(StoredMediaAssetRepository::class);
        self::assertInstanceOf(StoredMediaAssetRepository::class, $repo);

        return $repo->count([]);
    }

    private function activeUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Med', 'Ya', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function cleanupMedia(): void
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['stored_media_assets'])) {
            $connection->executeStatement('DELETE FROM stored_media_assets');
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupMedia();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            if ($connection->createSchemaManager()->tablesExist(['institution_memberships'])) {
                $connection->executeStatement(
                    "DELETE FROM institution_memberships WHERE institution_id IN (SELECT id FROM institutions WHERE name = 'Medya Kurum Gizli')",
                );
            }
            if ($connection->createSchemaManager()->tablesExist(['institutions'])) {
                $connection->executeStatement("DELETE FROM institutions WHERE name = 'Medya Kurum Gizli'");
            }
            if ($connection->createSchemaManager()->tablesExist(['users'])) {
                $connection->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'media-%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
