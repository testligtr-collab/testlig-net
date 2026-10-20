<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NewsArticle;
use App\Entity\User;
use App\Enum\NewsArticleStatus;
use App\Enum\NewsFailureReason;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\NewsArticleException;
use App\Repository\UserRepository;
use App\Service\News\NewsArticleManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsArticleFlowTest extends WebTestCase
{
    public function testAnonymousTeacherAndStudentCannotManageNews(): void
    {
        $client = $this->browser();
        $client->request('GET', '/yonetim/haberler');
        self::assertResponseRedirects('/giris');

        $client->request('GET', '/haberler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Haberler');
        self::assertSelectorTextContains('body', 'Yayımlanmış haber yok.');

        $teacher = $this->browser();
        $this->loginAs($teacher, 'news_flow_teacher@example.com', UserRole::Teacher);
        $teacher->request('GET', '/yonetim/haberler');
        self::assertResponseStatusCodeSame(403);
        $teacher->request('GET', '/calisma-alani');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/yonetim/haberler"]');

        $student = $this->browser();
        $this->loginAs($student, 'news_flow_student@example.com', UserRole::Student);
        $student->request('GET', '/yonetim/haberler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDraftDoesNotLeakAndAnotherAdminPublishes(): void
    {
        $this->createPrivilegedUser('news_flow_author@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_flow_publisher@example.com', UserRole::Admin);

        $author = $this->browser();
        $this->submitLogin($author, 'news_flow_author@example.com');
        $author->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.panel-nav-group--site a[href="/yonetim/haberler"]');

        $author->request('GET', '/yonetim/haberler');
        $author->submit($author->getCrawler()->selectButton('Kategoriyi kaydet')->form([
            'name' => 'Platform',
        ]));
        $author->followRedirect();
        self::assertSelectorTextContains('body', 'Platform');

        $author->request('GET', '/yonetim/haberler/yeni');
        $author->submit($author->getCrawler()->selectButton('Taslağı kaydet')->form([
            'title' => 'Taslak Haber Basligi',
            'summary' => 'Bu taslak sitede gorunmemeli.',
            'body' => 'Taslak metni yalnizca yonetim ekraninda kalir.',
            'category_id' => '',
        ]));
        self::assertResponseRedirects();
        $author->followRedirect();
        self::assertSelectorTextContains('body', 'Taslak');
        $draftPath = (string) parse_url((string) $author->getRequest()->getUri(), \PHP_URL_PATH);

        $author->request('GET', '/yonetim/haberler/yeni');
        $categoryId = $author->getCrawler()->filter('select[name="category_id"] option')->eq(1)->attr('value');
        self::assertIsString($categoryId);
        $author->submit($author->getCrawler()->selectButton('Taslağı kaydet')->form([
            'title' => 'Yayin Haber Basligi',
            'summary' => 'Bu ozet yalnizca yayindan sonra gorunur.',
            'body' => 'Yayin metni kategori ile birlikte incelemeye gider.',
            'category_id' => $categoryId,
        ]));
        $author->followRedirect();
        $review = $author->getCrawler()->selectButton('İncelemeye gönder')->form();
        $author->submit($review);
        $author->followRedirect();
        self::assertSelectorTextContains('body', 'İncelemede');
        $id = basename((string) parse_url((string) $author->getRequest()->getUri(), \PHP_URL_PATH));
        $token = $author->getCrawler()->filter('#news-publish-token')->attr('value');
        self::assertIsString($token);
        $author->request('POST', '/yonetim/haberler/'.$id.'/yayimla', ['_token' => $token]);
        self::assertResponseRedirects();
        $author->followRedirect();
        self::assertSelectorTextContains('body', 'Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.');
        self::assertSelectorTextContains('body', 'İncelemede');

        $public = $this->browser();
        $public->request('GET', '/haberler/yayin-haber-basligi');
        self::assertResponseStatusCodeSame(404);
        $public->request('GET', '/haberler/taslak-haber-basligi');
        self::assertResponseStatusCodeSame(404);
        $public->request('GET', $draftPath);
        self::assertResponseRedirects('/giris');
        $public->request('GET', '/haberler');
        self::assertStringNotContainsString('Yayin Haber Basligi', (string) $public->getResponse()->getContent());
        self::assertStringNotContainsString('Taslak Haber Basligi', (string) $public->getResponse()->getContent());
        $public->request('GET', '/');
        self::assertStringNotContainsString('Yayin Haber Basligi', (string) $public->getResponse()->getContent());
        self::assertSelectorTextContains('#haberler', 'Platform duyuruları ve eğitim rehberleri burada yer alacak.');

        $publisher = $this->browser();
        $this->submitLogin($publisher, 'news_flow_publisher@example.com');
        $publisher->request('GET', '/yonetim/haberler/'.$id);
        $publisher->submit($publisher->getCrawler()->selectButton('Yayımla')->form());
        $publisher->followRedirect();
        self::assertSelectorTextContains('body', 'Yayında');

        $published = $this->browser();
        $published->request('GET', '/haberler');
        self::assertSelectorTextContains('body', 'Yayin Haber Basligi');
        self::assertStringNotContainsString('Taslak Haber Basligi', (string) $published->getResponse()->getContent());
        $published->request('GET', '/haberler/yayin-haber-basligi');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Yayin Haber Basligi');
        $published->request('GET', '/');
        self::assertSelectorTextContains('#haberler', 'Yayin Haber Basligi');
        self::assertStringNotContainsString('Taslak Haber Basligi', (string) $published->getResponse()->getContent());

        $archivist = $this->browser();
        $this->submitLogin($archivist, 'news_flow_publisher@example.com');
        $archivist->request('GET', '/yonetim/haberler/'.$id);
        $archivist->submit($archivist->getCrawler()->selectButton('Arşivle')->form());
        $archivist->followRedirect();
        self::assertSelectorTextContains('body', 'Arşivlenmiş');
        $afterArchive = $this->browser();
        $afterArchive->request('GET', '/haberler/yayin-haber-basligi');
        self::assertResponseStatusCodeSame(404);
        $afterArchive->request('GET', '/');
        self::assertStringNotContainsString('Yayin Haber Basligi', (string) $afterArchive->getResponse()->getContent());
    }

    public function testLastEditorCannotPublishWhenSomeoneElseSubmits(): void
    {
        $this->createPrivilegedUser('news_flow_sep_a@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_flow_sep_b@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_flow_sep_c@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_flow_sep_d@example.com', UserRole::Admin);

        $author = $this->browser();
        $this->submitLogin($author, 'news_flow_sep_a@example.com');
        $author->request('GET', '/yonetim/haberler');
        $author->submit($author->getCrawler()->selectButton('Kategoriyi kaydet')->form([
            'name' => 'Ayrim',
        ]));
        $author->followRedirect();
        $author->request('GET', '/yonetim/haberler/yeni');
        $categoryId = $author->getCrawler()->filter('select[name="category_id"] option')->eq(1)->attr('value');
        self::assertIsString($categoryId);
        $author->submit($author->getCrawler()->selectButton('Taslağı kaydet')->form([
            'title' => 'Aynim Haber Basligi',
            'summary' => 'Ilk yazarin ozeti burada durur.',
            'body' => 'Ilk yazarin metni daha sonra baska yonetici degistirir.',
            'category_id' => $categoryId,
        ]));
        $author->followRedirect();
        $path = (string) parse_url((string) $author->getRequest()->getUri(), \PHP_URL_PATH);

        $editor = $this->browser();
        $this->submitLogin($editor, 'news_flow_sep_b@example.com');
        $editor->request('GET', $path);
        $editor->submit($editor->getCrawler()->selectButton('Taslağı güncelle')->form([
            'title' => 'B Duzenledi Baslik',
            'summary' => 'Son duzenleyen bu ozeti yazdi.',
            'body' => 'Son duzenleyen bu govdeyi yazdi ve yayimlayamaz.',
            'category_id' => $categoryId,
        ]));
        $editor->followRedirect();
        self::assertSelectorTextContains('h1', 'B Duzenledi Baslik');

        $submitter = $this->browser();
        $this->submitLogin($submitter, 'news_flow_sep_c@example.com');
        $submitter->request('GET', $path);
        $submitter->submit($submitter->getCrawler()->selectButton('İncelemeye gönder')->form());
        $submitter->followRedirect();
        self::assertSelectorTextContains('body', 'İncelemede');

        $editor->request('GET', $path);
        self::assertSelectorExists('button[disabled]:contains("Yayımla")');
        self::assertSelectorTextContains('body', 'Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.');
        $token = $editor->getCrawler()->filter('#news-publish-token')->attr('value');
        self::assertIsString($token);
        $editor->request('POST', $path.'/yayimla', ['_token' => $token]);
        $editor->followRedirect();
        self::assertSelectorTextContains('body', 'Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.');
        self::assertSelectorTextContains('body', 'İncelemede');

        $publisher = $this->browser();
        $this->submitLogin($publisher, 'news_flow_sep_d@example.com');
        $publisher->request('GET', $path);
        $publisher->submit($publisher->getCrawler()->selectButton('Yayımla')->form());
        $publisher->followRedirect();
        self::assertSelectorTextContains('body', 'Yayında');
        self::assertSelectorTextContains('h1', 'B Duzenledi Baslik');
    }

    public function testStaleDraftEditCannotChangePublishedText(): void
    {
        $this->createPrivilegedUser('news_flow_lock_author@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_flow_lock_publisher@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_flow_lock_editor@example.com', UserRole::Admin);
        $author = $this->reloadAdmin('news_flow_lock_author@example.com');
        $publisher = $this->reloadAdmin('news_flow_lock_publisher@example.com');
        $editor = $this->reloadAdmin('news_flow_lock_editor@example.com');
        $news = $this->newsManager();
        $em = $this->entityManager();
        $category = $news->createCategory($author, 'Kilit');
        $article = $news->createDraft(
            $author,
            'Kilitli Baslik',
            'Bu ozet kilit testinde durur.',
            'Bu govde yayindan sonra eski taslakla degismemeli.',
            $category->getId(),
        );
        $news->submitForReview($author, $article);
        $news->publish($publisher, $article);
        $id = $article->getId();

        $em->clear();
        $stale = $em->find(NewsArticle::class, $id);
        self::assertInstanceOf(NewsArticle::class, $stale);
        $status = new \ReflectionProperty(NewsArticle::class, 'status');
        $title = new \ReflectionProperty(NewsArticle::class, 'title');
        $status->setValue($stale, NewsArticleStatus::Draft);
        $title->setValue($stale, 'Eski taslak basligi');

        try {
            $news->updateDraft(
                $editor,
                $stale,
                'Eski taslak basligi',
                'Bu ozet eski taslaktan gelir ve yayini bozmamali.',
                'Eski govde yayindaki metnin uzerine yazilmamalidir.',
                $category->getId(),
            );
            self::fail('Stale draft edit must not commit.');
        } catch (NewsArticleException $e) {
            self::assertSame(NewsFailureReason::InvalidTransition, $e->getReason());
        }

        $em->clear();
        $fresh = $em->find(NewsArticle::class, $id);
        self::assertInstanceOf(NewsArticle::class, $fresh);
        self::assertSame(NewsArticleStatus::Published, $fresh->getStatus());
        self::assertSame('Kilitli Baslik', $fresh->getTitle());
    }

    public function testSuspendedOrDemotedActorCannotWriteNews(): void
    {
        $this->createPrivilegedUser('news_flow_suspended@example.com', UserRole::Admin);
        $this->createPrivilegedUser('news_flow_demoted@example.com', UserRole::Admin);
        $suspended = $this->reloadAdmin('news_flow_suspended@example.com');
        $demoted = $this->reloadAdmin('news_flow_demoted@example.com');
        $news = $this->newsManager();
        $em = $this->entityManager();
        $article = $news->createDraft(
            $suspended,
            'Pasif Yazar Basligi',
            'Bu ozet pasif aktorden sonra durur.',
            'Bu govde yetkisi kalkan aktorden sonra degismemeli.',
            null,
        );
        $suspended->transitionTo(UserStatus::Suspended);
        $em->flush();

        try {
            $news->updateDraft(
                $suspended,
                $article,
                'Pasif Yazar Basligi',
                'Bu ozet pasif aktorden sonra durur.',
                'Bu govde yetkisi kalkan aktorden sonra degismemeli.',
                null,
            );
            self::fail('Suspended actor must not update news.');
        } catch (NewsArticleException $e) {
            self::assertSame(NewsFailureReason::Unauthorized, $e->getReason());
        }

        $demoted->setGlobalRoles([]);
        $em->flush();
        try {
            $news->createDraft(
                $demoted,
                'Yetkisiz Yeni Baslik',
                'Bu ozet yetkisiz aktorden gelmemeli.',
                'Bu govde yetkisiz aktorden kaydedilmemeli.',
                null,
            );
            self::fail('Demoted actor must not create news.');
        } catch (NewsArticleException $e) {
            self::assertSame(NewsFailureReason::Unauthorized, $e->getReason());
        }

        $em->clear();
        $fresh = $em->find(NewsArticle::class, $article->getId());
        self::assertInstanceOf(NewsArticle::class, $fresh);
        self::assertSame('Pasif Yazar Basligi', $fresh->getTitle());
        self::assertSame(NewsArticleStatus::Draft, $fresh->getStatus());
    }

    public function testHtmlAndMissingCsrfAreRejected(): void
    {
        $this->createPrivilegedUser('news_flow_editor@example.com', UserRole::Admin);
        $client = $this->browser();
        $this->submitLogin($client, 'news_flow_editor@example.com');

        $client->request('POST', '/yonetim/haberler/kategoriler', ['name' => 'Deneme']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/yonetim/haberler/yeni');
        $client->submit($client->getCrawler()->selectButton('Taslağı kaydet')->form([
            'title' => 'Bozuk Haber',
            'summary' => 'Bu ozet html icermemeli.',
            'body' => 'Bu metin <b>etiket</b> tasir ve kaydedilmez.',
            'category_id' => '',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'HTML kullanılamaz.');

        $public = $this->browser();
        $public->request('GET', '/haberler');
        self::assertStringNotContainsString('Bozuk Haber', (string) $public->getResponse()->getContent());
    }

    private function reloadAdmin(string $email): User
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

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): void
    {
        $this->createPrivilegedUser($email, $role);
        $this->submitLogin($client, $email);
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
                    "DELETE FROM users WHERE normalized_email LIKE 'news_flow_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
