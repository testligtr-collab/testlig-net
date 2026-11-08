<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\NewsArticle;
use App\Entity\User;
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

final class AdminNewsFormHttpTest extends WebTestCase
{
    public function testAuthorizedNewAndEditForms(): void
    {
        $this->createPrivilegedUser('news_form_admin@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_form_admin@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Form Kategori');
        $article = $news->createDraft(
            $author,
            'Duzenlenecek haber',
            'Duzenlenecek ozet burada durur.',
            'Duzenlenecek govde burada durur.',
            $category->getId(),
        );
        $id = $article->getId()->toRfc4122();

        $client = $this->browser();
        $this->submitLogin($client, 'news_form_admin@example.com');
        $auditsBefore = $this->auditCount();
        $articlesBefore = $this->tableCount('news_articles');

        $client->request('GET', '/yonetim/haberler/yeni');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Yeni haber');
        self::assertSelectorExists('a[href="/yonetim/haberler"]');
        self::assertSelectorExists('label[for="news-title"]');
        self::assertSelectorExists('label[for="news-summary"]');
        self::assertSelectorExists('label[for="news-category"]');
        self::assertSelectorExists('label[for="news-body"]');
        self::assertSelectorTextContains('.admin-news-article-form button[type="submit"]', 'Taslağı kaydet');
        self::assertSame($auditsBefore, $this->auditCount());
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));

        $client->request('GET', '/yonetim/haberler/'.$id.'/duzenle');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Haberi düzenle');
        self::assertSelectorExists('a[href="/yonetim/haberler/'.$id.'"]');
        self::assertSame('Duzenlenecek haber', $client->getCrawler()->filter('#news-title')->attr('value'));
        self::assertSame('Duzenlenecek ozet burada durur.', $client->getCrawler()->filter('#news-summary')->attr('value'));
        self::assertSelectorTextContains('#news-body', 'Duzenlenecek govde burada durur.');
        self::assertSame($category->getId()->toRfc4122(), $client->getCrawler()->filter('#news-category option[selected]')->attr('value'));
        self::assertSelectorTextContains('.admin-news-article-form button[type="submit"]', 'Taslağı güncelle');
        self::assertSame($auditsBefore, $this->auditCount());
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));
    }

    public function testAnonymousAndUnauthorizedCannotOpenForms(): void
    {
        $client = $this->browser();
        $client->request('GET', '/yonetim/haberler/yeni');
        self::assertResponseRedirects('/giris');

        $this->createPrivilegedUser('news_form_teacher@example.com', UserRole::Teacher);
        $teacher = $this->browser();
        $this->submitLogin($teacher, 'news_form_teacher@example.com');
        $teacher->request('GET', '/yonetim/haberler/yeni');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $teacher->request('GET', '/yonetim/haberler/00000000-0000-4000-8000-000000000001/duzenle');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNonDraftAndMissingEditAreRejected(): void
    {
        $this->createPrivilegedUser('news_form_status@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_form_publisher@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_form_status@example.com');
        $publisher = $this->reloadUser('news_form_publisher@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Durum');
        $review = $news->createDraft(
            $author,
            'Inceleme formu',
            'Inceleme ozeti burada durur.',
            'Inceleme govdesi burada durur.',
            $category->getId(),
        );
        $news->submitForReview($author, $review);
        $published = $news->createDraft(
            $author,
            'Yayin formu',
            'Yayin ozeti burada durur.',
            'Yayin govdesi burada durur.',
            $category->getId(),
        );
        $news->submitForReview($author, $published);
        $news->publish($publisher, $published);

        $client = $this->browser();
        $this->submitLogin($client, 'news_form_status@example.com');
        $client->request('GET', '/yonetim/haberler/'.$review->getId()->toRfc4122().'/duzenle');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', '/yonetim/haberler/'.$published->getId()->toRfc4122().'/duzenle');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', '/yonetim/haberler/00000000-0000-4000-8000-000000000099/duzenle');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSuccessfulCreateAndUpdateRedirectToShow(): void
    {
        $this->createPrivilegedUser('news_form_save@example.com', UserRole::Admin);
        $client = $this->browser();
        $this->submitLogin($client, 'news_form_save@example.com');
        $client->request('GET', '/yonetim/haberler');
        $client->submit($client->getCrawler()->selectButton('Kategori ekle')->form([
            'name' => 'Kayit',
        ]));
        $client->followRedirect();

        $client->request('GET', '/yonetim/haberler/yeni');
        $categoryId = $client->getCrawler()->filter('#news-category option')->eq(1)->attr('value');
        self::assertIsString($categoryId);
        $client->submit($client->getCrawler()->selectButton('Taslağı kaydet')->form([
            'title' => 'Kayitli haber basligi',
            'summary' => 'Kayitli ozet burada durur.',
            'body' => 'Kayitli govde burada durur ve yeterli uzunluktadir.',
            'category_id' => $categoryId,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Kayitli haber basligi');
        $path = (string) parse_url((string) $client->getRequest()->getUri(), \PHP_URL_PATH);
        self::assertMatchesRegularExpression('#^/yonetim/haberler/[0-9a-fA-F-]{36}$#', $path);

        $client->request('GET', $path.'/duzenle');
        $client->submit($client->getCrawler()->selectButton('Taslağı güncelle')->form([
            'title' => 'Guncellenen haber basligi',
            'summary' => 'Guncellenen ozet burada durur.',
            'body' => 'Guncellenen govde burada durur ve yeterli uzunluktadir.',
            'category_id' => $categoryId,
        ]));
        self::assertResponseRedirects($path);
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Guncellenen haber basligi');
        self::assertSelectorExists('a[href="'.$path.'/duzenle"]');
    }

    public function testInvalidCreateKeepsSafeInputsAndRejectsForeignCategory(): void
    {
        $this->createPrivilegedUser('news_form_invalid@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_form_invalid@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Gecerli');
        $client = $this->browser();
        $this->submitLogin($client, 'news_form_invalid@example.com');

        $articlesBefore = $this->tableCount('news_articles');
        $auditsBefore = $this->auditCount();
        $client->request('GET', '/yonetim/haberler/yeni');
        $form = $client->getCrawler()->selectButton('Taslağı kaydet')->form();
        $form->disableValidation();
        $client->submit($form, [
            'title' => 'Korunan baslik',
            'summary' => 'Korunan ozet burada.',
            'body' => 'Korunan govde <b>etiket</b> tasir ve kaydedilmez.',
            'category_id' => '00000000-0000-4000-8000-000000000099',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('body', 'HTML kullanılamaz.');
        self::assertSame('Korunan baslik', $client->getCrawler()->filter('#news-title')->attr('value'));
        self::assertSame('Korunan ozet burada.', $client->getCrawler()->filter('#news-summary')->attr('value'));
        self::assertSelectorTextContains('#news-body', 'Korunan govde <b>etiket</b> tasir ve kaydedilmez.');
        self::assertSame('', (string) $client->getCrawler()->filter('#news-category option[selected]')->attr('value'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;b&gt;etiket&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>etiket</b>', $html);
        self::assertStringNotContainsString('00000000-0000-4000-8000-000000000099', $html);
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));
        self::assertSame($auditsBefore, $this->auditCount());

        $client->request('GET', '/yonetim/haberler/yeni');
        $client->submit($client->getCrawler()->selectButton('Taslağı kaydet')->form([
            'title' => 'ab',
            'summary' => 'Kisa ozet',
            'body' => 'Kisa govde metni',
            'category_id' => $category->getId()->toRfc4122(),
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('ab', $client->getCrawler()->filter('#news-title')->attr('value'));
        self::assertSame($category->getId()->toRfc4122(), $client->getCrawler()->filter('#news-category option[selected]')->attr('value'));
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));
    }

    public function testInvalidUpdateKeepsSafeInputsWithoutWrite(): void
    {
        $this->createPrivilegedUser('news_form_update_err@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_form_update_err@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Guncelle');
        $article = $news->createDraft(
            $author,
            'Orijinal baslik',
            'Orijinal ozet burada durur.',
            'Orijinal govde burada durur ve kayitlidir.',
            $category->getId(),
        );
        $id = $article->getId()->toRfc4122();
        $client = $this->browser();
        $this->submitLogin($client, 'news_form_update_err@example.com');
        $client->request('GET', '/yonetim/haberler/'.$id.'/duzenle');
        $articlesBefore = $this->tableCount('news_articles');
        $auditsBefore = $this->auditCount();
        $form = $client->getCrawler()->selectButton('Taslağı güncelle')->form();
        $form->disableValidation();
        $client->submit($form, [
            'title' => 'Hatali guncelleme',
            'summary' => 'Hatali ozet burada.',
            'body' => 'Hatali govde <script>alert(1)</script> tasir.',
            'category_id' => 'not-a-uuid',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('h1', 'Haberi düzenle');
        self::assertSame('Hatali guncelleme', $client->getCrawler()->filter('#news-title')->attr('value'));
        self::assertSame('Hatali ozet burada.', $client->getCrawler()->filter('#news-summary')->attr('value'));
        self::assertSelectorTextContains('#news-body', 'Hatali govde <script>alert(1)</script> tasir.');
        self::assertSame('', (string) $client->getCrawler()->filter('#news-category option[selected]')->attr('value'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('not-a-uuid', $html);
        self::assertSame($articlesBefore, $this->tableCount('news_articles'));
        self::assertSame($auditsBefore, $this->auditCount());

        $fresh = $this->entityManager()->find(NewsArticle::class, $article->getId());
        self::assertInstanceOf(NewsArticle::class, $fresh);
        self::assertSame('Orijinal baslik', $fresh->getTitle());
        self::assertSame('Orijinal ozet burada durur.', $fresh->getSummary());
        self::assertSame('Orijinal govde burada durur ve kayitlidir.', $fresh->getBody());
    }

    public function testUpdateCsrfAndNonDraftTransitionStayDenied(): void
    {
        $this->createPrivilegedUser('news_form_csrf@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_form_csrf_pub@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_form_csrf@example.com');
        $publisher = $this->reloadUser('news_form_csrf_pub@example.com');
        $news = $this->newsManager();
        $category = $news->createCategory($author, 'Csrf');
        $draft = $news->createDraft(
            $author,
            'Csrf taslak',
            'Csrf ozeti burada durur.',
            'Csrf govdesi burada durur ve yeterlidir.',
            $category->getId(),
        );
        $published = $news->createDraft(
            $author,
            'Csrf yayin',
            'Csrf yayin ozeti burada.',
            'Csrf yayin govdesi burada durur ve yeterlidir.',
            $category->getId(),
        );
        $news->submitForReview($author, $published);
        $news->publish($publisher, $published);

        $client = $this->browser();
        $this->submitLogin($client, 'news_form_csrf@example.com');
        $draftId = $draft->getId()->toRfc4122();
        $client->request('POST', '/yonetim/haberler/'.$draftId, [
            'title' => 'CSRF denemesi',
            'summary' => 'CSRF ozeti burada durur.',
            'body' => 'CSRF govdesi burada durur ve yeterlidir.',
            'category_id' => $category->getId()->toRfc4122(),
            '_token' => 'bad-token',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $publishedId = $published->getId()->toRfc4122();
        $client->request('GET', '/yonetim/haberler/'.$publishedId);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/yonetim/haberler/'.$publishedId, [
            'title' => 'Yayina yazma',
            'summary' => 'Yayina yazma ozeti burada.',
            'body' => 'Yayina yazma govdesi burada durur ve yeterlidir.',
            'category_id' => $category->getId()->toRfc4122(),
            '_token' => $this->csrfFor($client, 'news_article_'.$publishedId),
        ]);
        self::assertResponseRedirects('/yonetim/haberler/'.$publishedId);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Bu yayın geçişi yapılamaz.');
        $fresh = $this->entityManager()->find(NewsArticle::class, $published->getId());
        self::assertInstanceOf(NewsArticle::class, $fresh);
        self::assertSame('Csrf yayin', $fresh->getTitle());
    }

    public function testEscapesHostileValuesOnEditForm(): void
    {
        $this->createPrivilegedUser('news_form_xss@example.com', UserRole::Admin);
        $author = $this->reloadUser('news_form_xss@example.com');
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
        $title->setValue($article, '<img src=x onerror=alert(1)>Baslik');
        $summary->setValue($article, '<svg onload=alert(1)>Ozet uzun metin');
        $body->setValue($article, '<script>alert(1)</script>Govde uzun metin burada');
        $this->entityManager()->flush();

        $client = $this->browser();
        $this->submitLogin($client, 'news_form_xss@example.com');
        $client->request('GET', '/yonetim/haberler/'.$article->getId()->toRfc4122().'/duzenle');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;Baslik', $html);
        self::assertStringContainsString('&lt;svg onload=alert(1)&gt;Ozet uzun metin', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Govde uzun metin burada', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
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
                    "DELETE FROM users WHERE normalized_email LIKE 'news_form_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
