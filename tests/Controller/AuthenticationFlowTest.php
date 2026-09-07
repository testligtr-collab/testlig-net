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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationFlowTest extends WebTestCase
{
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

    public function testPendingSuspendedAndArchivedCannotLogin(): void
    {
        $client = static::createClient();
        $this->createUser('pending@example.com', 'Guclu-Parola-123!', UserStatus::PendingVerification);
        $this->createUser('suspended@example.com', 'Guclu-Parola-123!', UserStatus::Suspended);
        $this->createUser('archived@example.com', 'Guclu-Parola-123!', UserStatus::Archived);

        foreach (['pending@example.com', 'suspended@example.com', 'archived@example.com'] as $email) {
            $crawler = $client->request('GET', '/giris');
            $form = $crawler->selectButton('Giriş yap')->form([
                '_username' => $email,
                '_password' => 'Guclu-Parola-123!',
            ]);
            $client->submit($form);
            self::assertResponseRedirects('/giris');
            $client->followRedirect();
            self::assertSelectorExists('.flash-error, [role="alert"]');
        }
    }

    public function testWrongPasswordUsesGenericError(): void
    {
        $client = static::createClient();
        $this->createUser('wrong@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $crawler = $client->request('GET', '/giris');
        $form = $crawler->selectButton('Giriş yap')->form([
            '_username' => 'wrong@example.com',
            '_password' => 'Yanlis-Parola-999!',
        ]);
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'E-posta veya parola hatalı');
    }

    public function testAnonymousAccountRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
    }

    public function testLogoutClearsSession(): void
    {
        $client = static::createClient();
        $this->createUser('logout@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'logout@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();

        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/cikis', [
            '_csrf_token' => $this->getCsrf($client, 'logout'),
        ]);
        self::assertResponseRedirects('/');
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
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

    private function getCsrf(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $tokenId): string
    {
        $container = static::getContainer();
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $tokens */
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
