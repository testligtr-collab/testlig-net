<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\NewsArticle;
use App\Entity\User;
use App\Enum\NewsArticleStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\News\NewsArticleManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminNewsShowHttpTest extends WebTestCase
{
    public function testAuthorizedShowCoversStatusesActionsAndLongText(): void
    {
        $this->createPrivilegedUser('news_show_admin@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_show_publisher@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_show_admin@example.com');
        $publisher = $this->reloadUser('news_show_publisher@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Detay Kategori');
        $longCategory = $news->createCategory(
            $author,
            'Uzun kategori adi detay yan kartinda kirilmadan sarilir ve tasmadan devam eder',
        );

        $draft = $news->createDraft(
            $author,
            'Taslak detay basligi',
            'Taslak detay ozeti burada durur.',
            "Taslak detay govdesi.\nIkinci satir burada kalir.",
            $category->getId(),
        );
        $uncategorized = $news->createDraft(
            $author,
            'Kategorisiz detay',
            'Kategorisiz ozet burada durur.',
            'Kategorisiz govde burada durur ve yeterlidir.',
            null,
        );
        $review = $news->createDraft(
            $author,
            'Inceleme detay basligi',
            'Inceleme detay ozeti burada durur.',
            'Inceleme detay govdesi yayinlanmadan once bekler.',
            $category->getId(),
        );
        $news->submitForReview($author, $review);
        $published = $news->createDraft(
            $author,
            'Yayin detay basligi',
            'Yayin detay ozeti burada durur.',
            'Yayin detay govdesi baska yoneticinin onayini bekler.',
            $category->getId(),
        );
        $news->submitForReview($author, $published);
        $news->publish($publisher, $published);
        $archived = $news->createDraft(
            $author,
            'Arsiv detay basligi',
            'Arsiv detay ozeti burada durur.',
            'Arsiv detay govdesi yayin sonrasi arsivlenir.',
            $category->getId(),
        );
        $news->submitForReview($author, $archived);
        $news->publish($publisher, $archived);
        $news->archive($publisher, $archived);
        $long = $news->createDraft(
            $author,
            'Uzun detay basligi dar ekranda baslikta ve yan kartta sarilir ve yatay tasmadan devam eder',
            'Uzun detay ozeti yan kart kadar uzamaz ve ana okuma kolonunda kirilmadan sarilir.',
            str_repeat('Uzun detay govde satiri okunabilir genislikte kalir. ', 18)."Son satir.\nYeni paragraf.",
            $longCategory->getId(),
        );

        $client = $this->browser();
        $this->submitLogin($client, 'news_show_admin@example.com');
        $auditsBefore = $this->auditCount();
        $articlesBefore = $this->tableCount('news_articles');

        $client->request('GET', '/yonetim/haberler/'.$draft->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Taslak detay basligi');
        self::assertSelectorTextContains('.admin-news-show-head .admin-status', 'Taslak');
        self::assertSelectorExists('a[href="/yonetim/haberler"]');
        self::assertSelectorTextContains('a[href="/yonetim/haberler/'.$draft->getId()->toRfc4122().'/duzenle"]', 'Haberi düzenle');
        self::assertSelectorTextContains('#news-read-heading', 'Haber içeriği');
        self::assertSelectorTextContains('.admin-news-show-summary', 'Taslak detay ozeti burada durur.');
        self::assertSelectorTextContains('.admin-news-show-body__text', 'Taslak detay govdesi.');
        self::assertSelectorTextContains('#news-actions-heading', 'İşlemler');
        self::assertSelectorTextContains('.admin-news-show-actions', 'İncelemeye gönder');
        self::assertSelectorTextContains('.admin-news-show-actions', 'Arşivle');
        self::assertSelectorTextContains('#news-meta-heading', 'Özet');
        self::assertSelectorTextContains('.admin-news-show-meta', 'Detay Kategori');
        self::assertSelectorExists('details.admin-news-show-tech summary');
        self::assertSelectorTextContains('.admin-news-show-tech', $draft->getId()->toRfc4122());
        self::assertSelectorTextContains('.admin-news-show-tech', $draft->getSlug());
        self::assertStringNotContainsString('news_show_admin@example.com', (string) $client->getResponse()->getContent());
        self::assertSame($auditsBefore, $this->auditCount());
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));
        self::assertSame(NewsArticleStatus::Draft, $draft->getStatus());

        $client->request('GET', '/yonetim/haberler/'.$uncategorized->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-news-show-meta', '—');

        $client->request('GET', '/yonetim/haberler/'.$review->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-news-show-head .admin-status', 'İncelemede');
        self::assertSelectorNotExists('a[href$="/duzenle"]');
        self::assertSelectorExists('button[disabled]');
        self::assertSelectorTextContains('body', 'Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.');
        self::assertSelectorExists('#news-publish-token');
        self::assertSelectorTextContains('.admin-news-show-actions', 'Taslağa al');

        $client->request('GET', '/yonetim/haberler/'.$published->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-news-show-head .admin-status', 'Yayında');
        self::assertSelectorExists('a[href="/haberler/'.$published->getSlug().'"]');
        self::assertSelectorTextContains('.admin-news-show-actions', 'Taslağa al');
        self::assertSelectorTextContains('.admin-news-show-actions', 'Arşivle');
        self::assertSelectorTextContains('.admin-news-show-meta', 'Yayın tarihi');

        $client->request('GET', '/yonetim/haberler/'.$archived->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-news-show-head .admin-status', 'Arşivlenmiş');
        self::assertSelectorTextContains('body', 'Bu haber arşivde. Sitede görünmez.');
        self::assertSelectorNotExists('#news-actions-heading');

        $client->request('GET', '/yonetim/haberler/'.$long->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Uzun detay basligi');
        self::assertSelectorTextContains('.admin-news-show-meta', 'Uzun kategori adi');
        self::assertSelectorTextContains('.admin-news-show-body__text', 'Uzun detay govde satiri');
        self::assertSame($auditsBefore, $this->auditCount());
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));
    }

    public function testAnonymousAndUnauthorizedCannotOpenShow(): void
    {
        $this->createPrivilegedUser('news_show_owner@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_show_owner@example.com');
        $news = $this->newsManager();
        $article = $news->createDraft(
            $author,
            'Kapali detay',
            'Kapali ozet burada durur.',
            'Kapali govde burada durur ve yeterlidir.',
            null,
        );
        $path = '/yonetim/haberler/'.$article->getId()->toRfc4122();

        $client = $this->browser();
        $client->request('GET', $path);
        self::assertResponseRedirects('/giris');

        $this->createPrivilegedUser('news_show_teacher@example.com', UserRole::Teacher);
        $teacher = $this->browser();
        $this->submitLogin($teacher, 'news_show_teacher@example.com');
        $teacher->request('GET', $path);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPublisherSeesEnabledPublishAndAuthorCannotPublish(): void
    {
        $this->createPrivilegedUser('news_show_sep_a@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_show_sep_b@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_show_sep_a@example.com');
        $publisher = $this->reloadUser('news_show_sep_b@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Ayrim Detay');
        $article = $news->createDraft(
            $author,
            'Ayrim detay basligi',
            'Ayrim detay ozeti burada durur.',
            'Ayrim detay govdesi burada durur ve yeterlidir.',
            $category->getId(),
        );
        $news->submitForReview($author, $article);
        $path = '/yonetim/haberler/'.$article->getId()->toRfc4122();

        $authorClient = $this->browser();
        $this->submitLogin($authorClient, 'news_show_sep_a@example.com');
        $authorClient->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[disabled]');
        self::assertSelectorTextContains('body', 'Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.');
        $statusBefore = $article->getStatus();
        $authorClient->request('POST', $path.'/yayimla', [
            '_token' => $this->csrfFor($authorClient, 'news_publish_'.$article->getId()->toRfc4122()),
        ]);
        self::assertResponseRedirects($path);
        $authorClient->followRedirect();
        self::assertSelectorTextContains('body', 'Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.');
        self::assertSelectorTextContains('.admin-news-show-head .admin-status', 'İncelemede');
        $fresh = $this->entityManager()->find(NewsArticle::class, $article->getId());
        self::assertInstanceOf(NewsArticle::class, $fresh);
        self::assertSame($statusBefore, $fresh->getStatus());

        $publisherClient = $this->browser();
        $this->submitLogin($publisherClient, 'news_show_sep_b@example.com');
        $publisherClient->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-news-show-actions', 'Yayımla');
        self::assertSelectorNotExists('.admin-news-show-actions button[disabled]');
    }

    public function testCsrfAndInvalidTransitionDoNotWrite(): void
    {
        $this->createPrivilegedUser('news_show_csrf@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_show_csrf_pub@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_show_csrf@example.com');
        $publisher = $this->reloadUser('news_show_csrf_pub@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Detay Csrf');
        $draft = $news->createDraft(
            $author,
            'Csrf detay taslak',
            'Csrf detay ozeti burada durur.',
            'Csrf detay govdesi burada durur ve yeterlidir.',
            $category->getId(),
        );
        $published = $news->createDraft(
            $author,
            'Csrf detay yayin',
            'Csrf detay yayin ozeti burada.',
            'Csrf detay yayin govdesi burada durur ve yeterlidir.',
            $category->getId(),
        );
        $news->submitForReview($author, $published);
        $news->publish($publisher, $published);

        $client = $this->browser();
        $this->submitLogin($client, 'news_show_csrf@example.com');
        $publishedId = $published->getId()->toRfc4122();
        $client->request('GET', '/yonetim/haberler/'.$publishedId);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/yonetim/haberler/'.$publishedId.'/yayimla', [
            '_token' => $this->csrfFor($client, 'news_publish_'.$publishedId),
        ]);
        self::assertResponseRedirects('/yonetim/haberler/'.$publishedId);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Bu yayın geçişi yapılamaz.');
        $freshPublished = $this->entityManager()->find(NewsArticle::class, $published->getId());
        self::assertInstanceOf(NewsArticle::class, $freshPublished);
        self::assertSame(NewsArticleStatus::Published, $freshPublished->getStatus());

        $draftId = $draft->getId()->toRfc4122();
        $client->request('POST', '/yonetim/haberler/'.$draftId.'/incelemeye', ['_token' => 'bad-token']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $freshDraft = $this->entityManager()->find(NewsArticle::class, $draft->getId());
        self::assertInstanceOf(NewsArticle::class, $freshDraft);
        self::assertSame(NewsArticleStatus::Draft, $freshDraft->getStatus());
    }

    public function testEscapesHostileValuesOnShow(): void
    {
        $this->createPrivilegedUser('news_show_xss@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_show_xss@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Guvenli kategori');
        $article = $news->createDraft(
            $author,
            'Guvenli baslik',
            'Guvenli ozet burada durur.',
            'Guvenli govde burada durur ve yeterlidir.',
            $category->getId(),
        );
        $title = new \ReflectionProperty(NewsArticle::class, 'title');
        $summary = new \ReflectionProperty(NewsArticle::class, 'summary');
        $body = new \ReflectionProperty(NewsArticle::class, 'body');
        $slug = new \ReflectionProperty(NewsArticle::class, 'slug');
        $title->setValue($article, '<img src=x onerror=alert(1)>Baslik');
        $summary->setValue($article, '<svg onload=alert(1)>Ozet uzun metin');
        $body->setValue($article, "<script>alert(1)</script>Govde\nIkinci satir");
        $slug->setValue($article, 'xss-slug"><img src=x onerror=alert(2)>');
        $this->entityManager()->flush();

        $client = $this->browser();
        $this->submitLogin($client, 'news_show_xss@example.com');
        $client->request('GET', '/yonetim/haberler/'.$article->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;Baslik', $html);
        self::assertStringContainsString('&lt;svg onload=alert(1)&gt;Ozet uzun metin', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Govde', $html);
        self::assertStringContainsString('xss-slug&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
    }

    private function csrfFor(KernelBrowser $client, string $tokenId): string
    {
        $session = $client->getRequest()->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($client->getRequest());
        try {
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);
            $token = $tokens->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();

        return $token;
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
                    "DELETE FROM users WHERE normalized_email LIKE 'news_show_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
