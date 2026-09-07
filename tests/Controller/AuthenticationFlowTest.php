<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AuthenticationFlowTest extends WebTestCase
{
    private const GENERIC_LOGIN_ERROR = 'Giriş bilgileri hatalı veya hesap şu anda kullanılamıyor.';

    public function testActiveUserCanLoginAndSeeAccount(): void
    {
        $client = static::createClient();
        $this->createUser('active@example.com', 'Guclu-Parola-123!', UserStatus::Active);

        $crawler = $client->request('GET', '/giris');
        $form = $crawler->selectButton('Giriş yap')->form([
            '_username' => 'Active@Example.com',
            '_password' => 'Guclu-Parola-123!',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/hesabim');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hesabım');
        self::assertSelectorTextContains('body', 'active@example.com');
        self::assertSelectorNotExists('body:contains("$2")');
        self::assertSelectorNotExists('body:contains("ROLE_")');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('active@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testLoginFailuresUseSameGenericMessage(): void
    {
        $client = static::createClient();
        $this->createUser('pending@example.com', 'Guclu-Parola-123!', UserStatus::PendingVerification);
        $this->createUser('suspended@example.com', 'Guclu-Parola-123!', UserStatus::Suspended);
        $this->createUser('archived@example.com', 'Guclu-Parola-123!', UserStatus::Archived);
        $this->createUser('wrong@example.com', 'Guclu-Parola-123!', UserStatus::Active);

        $cases = [
            ['missing@example.com', 'Guclu-Parola-123!'],
            ['wrong@example.com', 'Yanlis-Parola-999!'],
            ['pending@example.com', 'Guclu-Parola-123!'],
            ['suspended@example.com', 'Guclu-Parola-123!'],
            ['archived@example.com', 'Guclu-Parola-123!'],
        ];

        foreach ($cases as [$email, $password]) {
            $crawler = $client->request('GET', '/giris');
            $client->submit($crawler->selectButton('Giriş yap')->form([
                '_username' => $email,
                '_password' => $password,
            ]));
            self::assertResponseRedirects('/giris');
            $client->followRedirect();
            self::assertSelectorTextContains('body', self::GENERIC_LOGIN_ERROR);
            self::assertSelectorNotExists('body:contains("doğrulanmamış")');
            self::assertSelectorNotExists('body:contains("askıya")');
            self::assertSelectorNotExists('body:contains("artık kullanılamıyor")');
        }
    }

    public function testLoginPageOffersResendWithoutConfirmingPendingStatus(): void
    {
        $client = static::createClient();
        $client->request('GET', '/giris');
        self::assertSelectorTextContains('body', 'Yeniden gönder');
        self::assertSelectorNotExists('body:contains("pending")');
        self::assertSelectorNotExists('body:contains("doğrulanmayı bekleyen hesabınız")');
    }

    public function testAnonymousAccountRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
    }

    public function testGetLogoutDoesNotClearSession(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'logout-get@example.com');

        $client->request('GET', '/cikis');
        self::assertResponseStatusCodeSame(405);

        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
    }

    public function testPostLogoutWithValidCsrfClearsSession(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'logout-ok@example.com');

        $client->request('POST', '/cikis', [
            '_csrf_token' => $this->getCsrf($client, 'logout'),
        ]);
        self::assertResponseRedirects('/');
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
    }

    public function testPostLogoutWithInvalidCsrfDoesNotClearSession(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'logout-csrf@example.com');

        $client->request('POST', '/cikis', [
            '_csrf_token' => 'invalid-token',
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
    }

    public function testVerifyEndpointInvalidUuidIsGeneric(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dogrula/eposta', ['id' => 'not-a-uuid']);
        self::assertResponseRedirects('/giris');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Doğrulama bağlantısı geçersiz veya süresi dolmuş.');
        self::assertSelectorNotExists('body:contains("User")');
        self::assertSelectorNotExists('body:contains("pending")');
    }

    public function testVerifyEndpointUnknownUserIsGeneric(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dogrula/eposta', [
            'id' => Uuid::v7()->toRfc4122(),
            'expires' => (string) (time() + 3600),
            'signature' => 'x',
            'token' => 'y',
        ]);
        self::assertResponseRedirects('/giris');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Doğrulama bağlantısı geçersiz veya süresi dolmuş.');
    }

    public function testOpenRedirectIsBlocked(): void
    {
        $client = static::createClient();
        $this->createUser('redirect@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $client->request('GET', '/hesabim');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', 'https://evil.example/phish');
        $session->save();

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'redirect@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseRedirects('/hesabim');
    }

    public function testLoginThrottlingApplies(): void
    {
        $client = static::createClient();
        $this->createUser('throttle@example.com', 'Guclu-Parola-123!', UserStatus::Active);

        for ($i = 0; $i < 3; ++$i) {
            $crawler = $client->request('GET', '/giris');
            $client->submit($crawler->selectButton('Giriş yap')->form([
                '_username' => 'throttle@example.com',
                '_password' => 'Yanlis-Parola!',
            ]));
            $client->followRedirect();
        }

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'throttle@example.com',
            '_password' => 'Yanlis-Parola!',
        ]));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Çok fazla');
        self::assertSelectorNotExists('body:contains("throttle@example.com")');
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $this->createUser($email, 'Guclu-Parola-123!', UserStatus::Active);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function createUser(string $email, string $password, UserStatus $status): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, $password, 'Test', 'User', UserRole::Student);

        if (UserStatus::PendingVerification !== $status) {
            $lifecycle->markEmailVerifiedAndActivate($user);
            /** @var UserRepository $users */
            $users = static::getContainer()->get(UserRepository::class);
            if (UserStatus::Suspended === $status) {
                $user->transitionTo(UserStatus::Suspended);
                $users->save($user);
            } elseif (UserStatus::Archived === $status) {
                $user->transitionTo(UserStatus::Archived);
                $users->save($user);
            }
        }

        self::ensureKernelShutdown();

        return $user;
    }

    private function getCsrf(KernelBrowser $client, string $tokenId): string
    {
        $container = static::getContainer();
        /** @var CsrfTokenManagerInterface $tokens */
        $tokens = $container->get('security.csrf.token_manager');

        return $tokens->getToken($tokenId)->getValue();
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement('DELETE FROM users');
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
