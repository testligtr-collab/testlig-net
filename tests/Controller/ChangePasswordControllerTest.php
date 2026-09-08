<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class ChangePasswordControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hesabim/sifre-degistir');
        self::assertResponseRedirects('/giris');
    }

    public function testActiveUserCanChangePasswordAndMustReLogin(): void
    {
        $client = static::createClient();
        $this->createActiveUser($client, 'change-ok@example.com', 'Guclu-Parola-123!');
        $this->authenticate($client, 'change-ok@example.com', 'Guclu-Parola-123!');

        $crawler = $client->request('GET', '/hesabim/sifre-degistir');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Parolayı kaydet')->form([
            'change_password_form[currentPassword]' => 'Guclu-Parola-123!',
            'change_password_form[newPassword][first]' => 'Yeni-Guclu-Parola-999!',
            'change_password_form[newPassword][second]' => 'Yeni-Guclu-Parola-999!',
        ]));
        self::assertResponseRedirects('/giris');

        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'change-ok@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Giriş bilgileri hatalı');

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'change-ok@example.com',
            '_password' => 'Yeni-Guclu-Parola-999!',
        ]));
        self::assertResponseRedirects('/hesabim');
    }

    public function testWrongCurrentPasswordRejected(): void
    {
        $client = static::createClient();
        $this->createActiveUser($client, 'change-wrong@example.com', 'Guclu-Parola-123!');
        $this->authenticate($client, 'change-wrong@example.com', 'Guclu-Parola-123!');
        $crawler = $client->request('GET', '/hesabim/sifre-degistir');
        $client->submit($crawler->selectButton('Parolayı kaydet')->form([
            'change_password_form[currentPassword]' => 'Yanlis-Parola-000!',
            'change_password_form[newPassword][first]' => 'Yeni-Guclu-Parola-999!',
            'change_password_form[newPassword][second]' => 'Yeni-Guclu-Parola-999!',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Parola değiştirilemedi');
    }

    public function testSamePasswordRejected(): void
    {
        $client = static::createClient();
        $this->createActiveUser($client, 'change-same@example.com', 'Guclu-Parola-123!');
        $this->authenticate($client, 'change-same@example.com', 'Guclu-Parola-123!');
        $crawler = $client->request('GET', '/hesabim/sifre-degistir');
        $client->submit($crawler->selectButton('Parolayı kaydet')->form([
            'change_password_form[currentPassword]' => 'Guclu-Parola-123!',
            'change_password_form[newPassword][first]' => 'Guclu-Parola-123!',
            'change_password_form[newPassword][second]' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'farklı olmalıdır');
    }

    public function testInvalidCsrfRejected(): void
    {
        $client = static::createClient();
        $this->createActiveUser($client, 'change-csrf@example.com', 'Guclu-Parola-123!');
        $this->authenticate($client, 'change-csrf@example.com', 'Guclu-Parola-123!');
        $client->request('POST', '/hesabim/sifre-degistir', [
            'change_password_form' => [
                'currentPassword' => 'Guclu-Parola-123!',
                'newPassword' => ['first' => 'Yeni-Guclu-Parola-999!', 'second' => 'Yeni-Guclu-Parola-999!'],
                '_token' => 'invalid',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testChangePasswordCancelsResetTokens(): void
    {
        $client = static::createClient();
        $this->createActiveUser($client, 'change-sessions@example.com', 'Guclu-Parola-123!');
        $this->authenticate($client, 'change-sessions@example.com', 'Guclu-Parola-123!');

        /** @var UserRepository $users */
        $users = $client->getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('change-sessions@example.com');
        self::assertInstanceOf(User::class, $user);

        /** @var ResetPasswordRequestRepository $repo */
        $repo = $client->getContainer()->get(ResetPasswordRequestRepository::class);
        /** @var ResetPasswordHelperInterface $helper */
        $helper = $client->getContainer()->get(ResetPasswordHelperInterface::class);
        $repo->removeRequests($user);
        $helper->generateResetToken($user);
        self::assertNotNull($repo->getMostRecentNonExpiredRequestDate($user));

        $crawler = $client->request('GET', '/hesabim/sifre-degistir');
        $client->submit($crawler->selectButton('Parolayı kaydet')->form([
            'change_password_form[currentPassword]' => 'Guclu-Parola-123!',
            'change_password_form[newPassword][first]' => 'Yeni-Guclu-Parola-111!',
            'change_password_form[newPassword][second]' => 'Yeni-Guclu-Parola-111!',
        ]));
        self::assertResponseRedirects('/giris');

        $reloaded = $users->findOneByNormalizedEmail('change-sessions@example.com');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNull($repo->getMostRecentNonExpiredRequestDate($reloaded));
    }

    public function testEquatableInterfaceInvalidatesChangedPasswordUser(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->create('eq@example.com', 'Guclu-Parola-123!', 'Eq', 'User', UserRole::Student);
        $user->transitionTo(UserStatus::Active);
        $copy = clone $user;
        self::assertTrue($user->isEqualTo($copy));
        $user->setPassword('different-hash-value');
        self::assertFalse($user->isEqualTo($copy));
    }

    public function testOtherBrowserSessionIsForcedToLoginAfterPasswordChange(): void
    {
        $client = static::createClient();
        $email = 'change-dual-session@example.com';
        $oldPassword = 'Guclu-Parola-123!';
        $newPassword = 'Yeni-Guclu-Parola-999!';
        $this->createActiveUser($client, $email, $oldPassword);
        $this->authenticate($client, $email, $oldPassword);
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();

        $sessionCookiesA = [];
        foreach ($client->getCookieJar()->all() as $cookie) {
            $sessionCookiesA[] = clone $cookie;
        }

        $client->getCookieJar()->clear();
        $this->authenticate($client, $email, $oldPassword);
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/hesabim/sifre-degistir');
        $client->submit($crawler->selectButton('Parolayı kaydet')->form([
            'change_password_form[currentPassword]' => $oldPassword,
            'change_password_form[newPassword][first]' => $newPassword,
            'change_password_form[newPassword][second]' => $newPassword,
        ]));
        self::assertResponseRedirects('/giris');

        $client->getCookieJar()->clear();
        foreach ($sessionCookiesA as $cookie) {
            $client->getCookieJar()->set($cookie);
        }
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => $oldPassword,
        ]));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Giriş bilgileri hatalı');

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => $newPassword,
        ]));
        self::assertResponseRedirects('/hesabim');
    }

    public function testRoleRemovalInvalidatesExistingBrowserSession(): void
    {
        $client = static::createClient();
        $email = 'change-role-session@example.com';
        $password = 'Guclu-Parola-123!';
        $user = $this->createActiveUser($client, $email, $password);
        $user->addGlobalRole(UserRole::Moderator);
        /** @var UserRepository $users */
        $users = $client->getContainer()->get(UserRepository::class);
        $users->save($user);

        $this->authenticate($client, $email, $password);
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();

        $sessionCookies = [];
        foreach ($client->getCookieJar()->all() as $cookie) {
            $sessionCookies[] = clone $cookie;
        }

        $reloaded = $users->findOneByNormalizedEmail(mb_strtolower($email));
        self::assertInstanceOf(User::class, $reloaded);
        $reloaded->setGlobalRoles([UserRole::Student]);
        $users->save($reloaded);

        $client->getCookieJar()->clear();
        foreach ($sessionCookies as $cookie) {
            $client->getCookieJar()->set($cookie);
        }
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
    }

    private function authenticate(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function createActiveUser(KernelBrowser $client, string $email, string $password): User
    {
        /** @var UserFactory $factory */
        $factory = $client->getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = $client->getContainer()->get(UserAccountLifecycle::class);
        /** @var UserRepository $users */
        $users = $client->getContainer()->get(UserRepository::class);
        $existing = $users->findOneByNormalizedEmail(mb_strtolower($email));
        if ($existing instanceof User) {
            return $existing;
        }
        $user = $factory->createAndPersist($email, $password, 'Change', 'User', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['reset_password_requests'])) {
                $em->getConnection()->executeStatement('DELETE FROM reset_password_requests');
            }
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement('DELETE FROM users');
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
