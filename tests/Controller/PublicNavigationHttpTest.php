<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * M29-cms: every public site shell (homepage, public pages, catalog, legal)
 * renders the same working main navigation and account actions.
 */
final class PublicNavigationHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';
    private const EMAIL = 'public_nav_member@example.com';

    private const LABELS = ['Ana Sayfa', 'Sınıflar', 'Haberler'];
    private const HREFS = ['/', '/dersler', '/haberler'];

    private const DEAD_ANCHORS = ['#sinavlar', '#ogrenme', '#guven', '#akis', '#testler', '#dokumanlar', '#simulasyonlar', '#videolar', '#soru-cevap', '#oyunlar'];

    protected function tearDown(): void
    {
        try {
            if (static::$booted) {
                $em = static::getContainer()->get(EntityManagerInterface::class);
                self::assertInstanceOf(EntityManagerInterface::class, $em);
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'public_nav_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function shells(): iterable
    {
        yield 'homepage shell' => ['/', '/'];
        yield 'catalog shell' => ['/dersler', '/dersler'];
        yield 'public page (news)' => ['/haberler', '/haberler'];
        yield 'public page (login)' => ['/giris', null];
        yield 'public page (register)' => ['/kayit', null];
        yield 'legal shell (privacy)' => ['/gizlilik', null];
        yield 'legal shell (children)' => ['/cocuk-ve-veli-bilgilendirmesi', null];
    }

    #[DataProvider('shells')]
    public function testAnonymousVisitorSeesTheSameNavigationEverywhere(string $path, ?string $currentHref): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful($path);
        $html = (string) $client->getResponse()->getContent();

        foreach (['nav[aria-label="Ana menü"]', 'nav[aria-label="Mobil ana menü"]'] as $selector) {
            self::assertCount(1, $crawler->filter($selector), $path.' '.$selector);
            self::assertSame(self::LABELS, $this->texts($crawler, $selector.' a'), $path.' '.$selector);
            self::assertSame(self::HREFS, $this->attrs($crawler, $selector.' a'), $path.' '.$selector);
            $current = $this->attrs($crawler, $selector.' a[aria-current="page"]');
            self::assertSame(null === $currentHref ? [] : [$currentHref], $current, $path.' aria-current');
        }

        foreach (['.site-header__actions', '.mobile-drawer__actions'] as $actions) {
            self::assertSame(['Giriş Yap', 'Kayıt Ol'], $this->texts($crawler, $actions.' a'), $path.' '.$actions);
            self::assertSame(['/giris', '/kayit'], $this->attrs($crawler, $actions.' a'), $path.' '.$actions);
            self::assertCount(0, $crawler->filter($actions.' form'), $path.' anonymous must not see logout');
        }

        foreach (self::DEAD_ANCHORS as $dead) {
            self::assertStringNotContainsString($dead, $html, $path.' still links '.$dead);
        }

        self::assertSame(1, $crawler->filter('.nav-toggle[aria-expanded="false"][aria-controls]')->count(), $path);
        $controls = (string) $crawler->filter('.nav-toggle')->attr('aria-controls');
        self::assertSame(1, $crawler->filter('#'.$controls.'.mobile-drawer[hidden]')->count(), $path);
        self::assertSame('/', $crawler->filter('.site-header a.brand')->attr('href'), $path.' brand');

        foreach (['/gizlilik', '/kullanim-kosullari', '/cerez-politikasi', '/cocuk-ve-veli-bilgilendirmesi'] as $legal) {
            self::assertGreaterThan(0, $crawler->filter('footer a[href="'.$legal.'"]')->count(), $path.' footer '.$legal);
        }
    }

    public function testHomepageNewsPreviewLinksToTheRealNewsIndexAndKeepsEmptyState(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('#haberler a[href="/haberler"]')->count());
        self::assertGreaterThan(0, $crawler->filter('footer a[href="/haberler"]')->count());
        self::assertSame(0, $crawler->filter('footer a[href="#haberler"]')->count());
        self::assertSelectorTextContains('#haberler', 'Haberler ve öğrenme rehberleri');
    }

    public function testShowcaseCardsAreMarkedPreparingAndOfferNoFakeControls(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $cards = $crawler->filter('#vitrin article.hp-vcard');
        self::assertCount(6, $cards);
        $cards->each(static function (Crawler $card): void {
            self::assertStringContainsString('İçerikler hazırlanıyor', $card->text());
            self::assertCount(0, $card->filter('a, button, [role="button"]'));
            self::assertStringNotContainsString('KEŞFET', $card->text());
        });
    }

    public function testSignedInVisitorKeepsAccountAndCsrfLogoutInEveryShell(): void
    {
        $this->persistActiveStudent();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();

        foreach (['/', '/dersler', '/haberler', '/gizlilik'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);

            foreach (['.site-header__actions', '.mobile-drawer__actions'] as $actions) {
                self::assertGreaterThan(0, $crawler->filter($actions.' a[href="/hesabim"]')->count(), $path.' '.$actions);
                self::assertSame(0, $crawler->filter($actions.' a[href="/giris"]')->count(), $path.' '.$actions.' login shown to signed-in user');
                self::assertSame(0, $crawler->filter($actions.' a[href="/kayit"]')->count(), $path.' '.$actions.' register shown to signed-in user');

                $form = $crawler->filter($actions.' form.logout-form[action="/cikis"][method="post"]');
                self::assertCount(1, $form, $path.' '.$actions.' logout form');
                self::assertNotSame('', (string) $form->filter('input[name="_csrf_token"]')->attr('value'), $path.' csrf');
                self::assertCount(1, $form->filter('button[type="submit"]'), $path);
            }
            self::assertSame(self::HREFS, $this->attrs($crawler, 'nav[aria-label="Ana menü"] a'), $path);
        }

        $crawler = $client->request('GET', '/');
        $client->submit($crawler->filter('.site-header__actions form.logout-form')->form());
        self::assertResponseRedirects();
        $client->followRedirect();
        $crawler = $client->request('GET', '/');
        self::assertSame(['/giris', '/kayit'], $this->attrs($crawler, '.site-header__actions a'));
    }

    /**
     * @return list<string>
     */
    private function texts(Crawler $crawler, string $selector): array
    {
        return array_values($crawler->filter($selector)->each(static fn (Crawler $node): string => trim($node->text())));
    }

    /**
     * @return list<string>
     */
    private function attrs(Crawler $crawler, string $selector): array
    {
        return array_values($crawler->filter($selector)->each(static fn (Crawler $node): string => (string) $node->attr('href')));
    }

    private function persistActiveStudent(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist(self::EMAIL, self::PASSWORD, 'Menu', 'Ziyaretci', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        self::ensureKernelShutdown();
    }
}
