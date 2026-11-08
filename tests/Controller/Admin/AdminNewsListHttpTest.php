<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\NewsArticle;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\NewsArticleRepository;
use App\Repository\UserRepository;
use App\Service\News\NewsArticleManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminNewsListHttpTest extends WebTestCase
{
    public function testAuthorizedListShowsCreateActionAndStatuses(): void
    {
        $this->createPrivilegedUser('news_list_admin@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_list_publisher@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_list_admin@example.com');
        $publisher = $this->reloadUser('news_list_publisher@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Duyurular');
        $longCategory = $news->createCategory(
            $author,
            'Uzun kategori adi satira sigmaz ve kartta veya tabloda kirilmadan sarilir',
        );
        $draft = $news->createDraft(
            $author,
            'Taslak haber basligi',
            'Taslak ozeti burada durur.',
            'Taslak govdesi yalniz yonetimde kalir.',
            $category->getId(),
        );
        $review = $news->createDraft(
            $author,
            'Inceleme haber basligi',
            'Inceleme ozeti burada durur.',
            'Inceleme govdesi yayinlanmadan once bekler.',
            $category->getId(),
        );
        $news->submitForReview($author, $review);
        $published = $news->createDraft(
            $author,
            'Yayin haber basligi',
            'Yayin ozeti burada durur.',
            'Yayin govdesi baska yoneticinin onayini bekler.',
            $category->getId(),
        );
        $news->submitForReview($author, $published);
        $news->publish($publisher, $published);
        $archived = $news->createDraft(
            $author,
            'Arsiv haber basligi',
            'Arsiv ozeti burada durur.',
            'Arsiv govdesi yayin sonrasi arsivlenir.',
            $category->getId(),
        );
        $news->submitForReview($author, $archived);
        $news->publish($publisher, $archived);
        $news->archive($publisher, $archived);
        $news->createDraft(
            $author,
            'Uzun haber basligi dar ekranda kartta ve masaustunde hucrede sarilir ve yatay tasmadan devam eder',
            'Uzun ozet metni listeye basilmaz.',
            'Uzun govde metni listeye basilmaz.',
            $longCategory->getId(),
        );

        $client = $this->browser();
        $this->submitLogin($client, 'news_list_admin@example.com');
        $auditsBefore = $this->auditCount();
        $articlesBefore = $this->tableCount('news_articles');
        $categoriesBefore = $this->tableCount('news_categories');
        $client->request('GET', '/yonetim/haberler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Haberler');
        self::assertSelectorTextContains('body', 'Haber taslaklarını ve yayın durumlarını yönetin.');
        self::assertSelectorExists('a[href="/yonetim/haberler/yeni"]');
        self::assertSelectorTextContains('a[href="/yonetim/haberler/yeni"]', 'Yeni haber');
        self::assertSelectorTextContains('#news-list-heading', 'Haber listesi');
        self::assertSelectorTextContains('#news-categories-title', 'Kategoriler');
        self::assertSelectorTextContains('body', 'Taslak');
        self::assertSelectorTextContains('body', 'İncelemede');
        self::assertSelectorTextContains('body', 'Yayında');
        self::assertSelectorTextContains('body', 'Arşivlenmiş');
        self::assertSelectorTextContains('body', 'Uzun haber basligi');
        self::assertSelectorTextContains('body', 'Uzun kategori adi');
        self::assertSelectorExists('.admin-status__dot');
        self::assertSelectorTextContains('a[href="/yonetim/haberler/'.$draft->getId()->toRfc4122().'"]', 'İncele');
        self::assertStringNotContainsString('Uzun ozet metni listeye basilmaz.', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('news_list_admin@example.com', (string) $client->getResponse()->getContent());
        self::assertSame($auditsBefore, $this->auditCount());
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));
        self::assertSame($categoriesBefore, $this->tableCount('news_categories'));
    }

    public function testEmptyListShowsEmptyState(): void
    {
        $this->createPrivilegedUser('news_list_empty@example.com', UserRole::Admin);
        $client = $this->browser();
        $this->submitLogin($client, 'news_list_empty@example.com');
        $client->request('GET', '/yonetim/haberler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Henüz haber yok');
        self::assertSelectorNotExists('.admin-news-table tbody tr');
    }

    public function testEscapesHostileTitleAndCategory(): void
    {
        $this->createPrivilegedUser('news_list_xss@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_list_xss@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Guvenli kategori');
        $article = NewsArticle::createDraft(
            $author,
            'XSS baslik denemesi',
            'xss-baslik-denemesi',
            'Guvenli ozet metni burada durur.',
            'Guvenli govde metni burada durur.',
            $category,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $title = new \ReflectionProperty(NewsArticle::class, 'title');
        $title->setValue($article, '<img src=x onerror=alert(1)>XSS');
        $categoryName = new \ReflectionProperty($category::class, 'name');
        $categoryName->setValue($category, '<script>alert(1)</script>Kategori');
        $em = $this->entityManager();
        $em->persist($article);
        $em->flush();

        $client = $this->browser();
        $this->submitLogin($client, 'news_list_xss@example.com');
        $client->request('GET', '/yonetim/haberler');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;XSS', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Kategori', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testAnonymousAndUnauthorizedAreDenied(): void
    {
        $client = $this->browser();
        $client->request('GET', '/yonetim/haberler');
        self::assertResponseRedirects('/giris');

        $this->createPrivilegedUser('news_list_teacher@example.com', UserRole::Teacher);
        $teacher = $this->browser();
        $this->submitLogin($teacher, 'news_list_teacher@example.com');
        $teacher->request('GET', '/yonetim/haberler');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $teacher->request('POST', '/yonetim/haberler/kategoriler', [
            'name' => 'Yetkisiz',
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCategoryCreateSuccessFailureCsrfAndUnauthorized(): void
    {
        $this->createPrivilegedUser('news_list_category@example.com', UserRole::Admin);
        $client = $this->browser();
        $this->submitLogin($client, 'news_list_category@example.com');
        $client->request('GET', '/yonetim/haberler');
        $client->submit($client->getCrawler()->selectButton('Kategori ekle')->form([
            'name' => 'Etkinlikler',
        ]));
        self::assertResponseRedirects('/yonetim/haberler');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Etkinlikler');
        self::assertSelectorTextContains('body', 'Kategori kaydedildi.');

        $client->submit($client->getCrawler()->selectButton('Kategori ekle')->form([
            'name' => 'a',
        ]));
        self::assertResponseRedirects('/yonetim/haberler');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Başlık, özet veya metin geçersiz. HTML kullanılamaz.');

        $client->request('POST', '/yonetim/haberler/kategoriler', [
            'name' => 'CSRF',
            '_token' => 'bad-token',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testListCategoryJoinDoesNotGrowWithRows(): void
    {
        $this->createPrivilegedUser('news_list_n1@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_list_n1@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Sayim');
        $categoryId = $category->getId();
        for ($i = 0; $i < 3; ++$i) {
            $news->createDraft(
                $author,
                'Kucuk haber '.$i,
                'Kucuk ozet '.$i.' burada durur.',
                'Kucuk govde '.$i.' burada durur.',
                $categoryId,
            );
        }

        $em = $this->entityManager();
        $repo = $em->getRepository(NewsArticle::class);
        self::assertInstanceOf(NewsArticleRepository::class, $repo);
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        $em->clear();
        $holder->reset();
        $smallArticles = $repo->findRecent(100);
        $small = $this->recordedStatements($holder);
        self::assertGreaterThan(0, $small);
        $holder->reset();
        foreach ($smallArticles as $article) {
            $article->getCategory()?->getName();
        }
        self::assertSame(0, $this->recordedStatements($holder), 'Joined categories must not lazy-load per row.');

        $author = $this->reloadUser('news_list_n1@example.com');
        $news = $this->newsManager();
        for ($i = 3; $i < 8; ++$i) {
            $news->createDraft(
                $author,
                'Buyuk haber '.$i,
                'Buyuk ozet '.$i.' burada durur.',
                'Buyuk govde '.$i.' burada durur.',
                $categoryId,
            );
        }

        $em = $this->entityManager();
        $repo = $em->getRepository(NewsArticle::class);
        self::assertInstanceOf(NewsArticleRepository::class, $repo);
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $em->clear();
        $holder->reset();
        $largeArticles = $repo->findRecent(100);
        $large = $this->recordedStatements($holder);
        self::assertGreaterThan(0, $large);
        self::assertSame($small, $large, 'News list SQL must not grow with rows (no N+1).');
        $holder->reset();
        foreach ($largeArticles as $article) {
            $article->getCategory()?->getName();
        }
        self::assertSame(0, $this->recordedStatements($holder), 'Joined categories must not lazy-load per row.');
    }

    public function testKeyboardFocusTargetsExistOnList(): void
    {
        $this->createPrivilegedUser('news_list_a11y@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_list_a11y@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Erisilebilir');
        $article = $news->createDraft(
            $author,
            'Erisilebilir haber',
            'Erisilebilir ozet burada durur.',
            'Erisilebilir govde burada durur.',
            $category->getId(),
        );
        $client = $this->browser();
        $this->submitLogin($client, 'news_list_a11y@example.com');
        $crawler = $client->request('GET', '/yonetim/haberler');
        self::assertResponseIsSuccessful();
        self::assertSame('news-category-name', $crawler->filter('label[for="news-category-name"]')->attr('for'));
        self::assertSame('news-category-name', $crawler->filter('#news-category-name')->attr('id'));
        self::assertGreaterThan(0, $crawler->filter('a[href="/yonetim/haberler/'.$article->getId()->toRfc4122().'"]')->count());
        self::assertSelectorExists('.admin-news-primary');
    }

    private function recordedStatements(DebugDataHolder $holder): int
    {
        $count = 0;
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $item) {
                $sql = $item['sql'] ?? null;
                if (\is_string($sql) && '' !== trim($sql)) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    private function auditCount(): int
    {
        return $this->tableCount('security_audit_events');
    }

    private function tableCount(string $table): int
    {
        $em = $this->entityManager();
        $schema = $em->getConnection()->createSchemaManager();
        if (!$schema->tablesExist([$table])) {
            return 0;
        }

        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function reloadUser(string $email): User
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function newsManager(): NewsArticleManager
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $manager = static::getContainer()->get(NewsArticleManager::class);
        self::assertInstanceOf(NewsArticleManager::class, $manager);

        return $manager;
    }

    private function entityManager(): EntityManagerInterface
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function submitLogin(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    private function createPrivilegedUser(string $email, UserRole $role): void
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
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            $schema = $connection->createSchemaManager();
            if ($schema->tablesExist(['news_articles'])) {
                $connection->executeStatement('DELETE FROM news_articles');
            }
            if ($schema->tablesExist(['news_categories'])) {
                $connection->executeStatement('DELETE FROM news_categories');
            }
            if ($schema->tablesExist(['users'])) {
                $connection->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'news_list_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
