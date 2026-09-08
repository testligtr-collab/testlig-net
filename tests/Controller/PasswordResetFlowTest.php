<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Service\EmailNormalizer;
use App\Service\PasswordManager;
use App\Service\PasswordResetNotifierInterface;
use App\Service\RateLimitKeyHasher;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class PasswordResetFlowTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const GENERIC = 'Eğer bu e-posta ile kullanılabilir bir hesap varsa, parola yenileme bağlantısı gönderildi.';

    private static int $ipCounter = 10;

    public function testForgotPasswordPageOpens(): void
    {
        $client = $this->newClient();
        $client->request('GET', '/sifremi-unuttum');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Şifremi unuttum');
    }

    public function testActiveUserReceivesResetEmailAndGenericResponse(): void
    {
        $client = $this->newClient();
        $email = 'reset-active-'.bin2hex(random_bytes(4)).'@example.com';
        $this->createUser($client, $email, 'Guclu-Parola-123!', UserStatus::Active);

        $crawler = $client->request('GET', '/sifremi-unuttum');
        $client->submit($crawler->selectButton('Bağlantı gönder')->form([
            'forgot_password_form[email]' => $email,
        ]));
        self::assertResponseRedirects('/sifremi-unuttum/eposta-kontrol');
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailHtmlBodyContains($message, 'Parolamı yenile');
        self::assertEmailTextBodyContains($message, 'sifre-yenile');

        $client->followRedirect();
        self::assertSelectorTextContains('body', self::GENERIC);
    }

    /**
     * @dataProvider nonActiveStatusesProvider
     */
    public function testNonActiveStatusesGetGenericResponseWithoutEmail(UserStatus $status): void
    {
        $client = $this->newClient();
        $email = 'reset-'.$status->value.'-'.bin2hex(random_bytes(3)).'@example.com';
        $this->createUser($client, $email, 'Guclu-Parola-123!', $status);

        $crawler = $client->request('GET', '/sifremi-unuttum');
        $client->submit($crawler->selectButton('Bağlantı gönder')->form([
            'forgot_password_form[email]' => $email,
        ]));
        self::assertResponseRedirects('/sifremi-unuttum/eposta-kontrol');
        self::assertEmailCount(0);
        $client->followRedirect();
        self::assertSelectorTextContains('body', self::GENERIC);
    }

    /**
     * @return iterable<string, array{0: UserStatus}>
     */
    public static function nonActiveStatusesProvider(): iterable
    {
        yield 'pending' => [UserStatus::PendingVerification];
        yield 'suspended' => [UserStatus::Suspended];
        yield 'archived' => [UserStatus::Archived];
    }

    public function testUnknownEmailGetsGenericResponse(): void
    {
        $client = $this->newClient();
        $crawler = $client->request('GET', '/sifremi-unuttum');
        $client->submit($crawler->selectButton('Bağlantı gönder')->form([
            'forgot_password_form[email]' => 'missing-reset@example.com',
        ]));
        self::assertResponseRedirects('/sifremi-unuttum/eposta-kontrol');
        self::assertEmailCount(0);
        $client->followRedirect();
        self::assertSelectorTextContains('body', self::GENERIC);
    }

    public function testInvalidCsrfRejected(): void
    {
        $client = $this->newClient();
        $client->request('POST', '/sifremi-unuttum', [
            'forgot_password_form' => [
                'email' => 'csrf-reset@example.com',
                '_token' => 'invalid',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMailerTransportFailureDoesNotThrowOrRevealDetails(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist('reset-smtp-fail@example.com', 'Guclu-Parola-123!', 'Mail', 'Fail', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);

        $failing = new class implements PasswordResetNotifierInterface {
            public function sendResetEmail(User $user, ResetPasswordToken $resetToken): void
            {
                throw new TransportException('SMTP unavailable');
            }
        };

        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        $requests = static::getContainer()->get(ResetPasswordRequestRepository::class);
        $users = static::getContainer()->get(UserRepository::class);
        $normalizer = static::getContainer()->get(EmailNormalizer::class);
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(ResetPasswordHelperInterface::class, $helper);
        self::assertInstanceOf(ResetPasswordRequestRepository::class, $requests);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(EmailNormalizer::class, $normalizer);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(LoggerInterface::class, $logger);

        $manager = new PasswordManager(
            $helper,
            $requests,
            $users,
            $normalizer,
            $failing,
            $hasher,
            $em,
            $logger,
        );

        $manager->requestReset('reset-smtp-fail@example.com');
    }

    public function testEmailRateLimitKeyDoesNotContainRawEmail(): void
    {
        self::bootKernel();
        /** @var RateLimitKeyHasher $hasher */
        $hasher = static::getContainer()->get(RateLimitKeyHasher::class);
        $key = $hasher->hashEmail('secret-user@example.com');
        self::assertStringNotContainsString('secret-user@example.com', $key);
        self::assertStringNotContainsString('example.com', $key);
        self::assertSame(64, \strlen($key));
    }

    public function testIpRateLimitApplies(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.200');
        for ($i = 0; $i < 5; ++$i) {
            $crawler = $client->request('GET', '/sifremi-unuttum');
            $client->submit($crawler->selectButton('Bağlantı gönder')->form([
                'forgot_password_form[email]' => "ip-limit-{$i}@example.com",
            ]));
            self::assertTrue($client->getResponse()->isRedirection() || $client->getResponse()->isSuccessful());
        }
        $crawler = $client->request('GET', '/sifremi-unuttum');
        $client->submit($crawler->selectButton('Bağlantı gönder')->form([
            'forgot_password_form[email]' => 'ip-limit-overflow@example.com',
        ]));
        self::assertResponseStatusCodeSame(429);
    }

    public function testEmailKeyRateLimitApplies(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.201');
        for ($i = 0; $i < 3; ++$i) {
            $crawler = $client->request('GET', '/sifremi-unuttum');
            $client->submit($crawler->selectButton('Bağlantı gönder')->form([
                'forgot_password_form[email]' => 'same-email-limit@example.com',
            ]));
            self::assertResponseRedirects('/sifremi-unuttum/eposta-kontrol');
        }
        $crawler = $client->request('GET', '/sifremi-unuttum');
        $client->submit($crawler->selectButton('Bağlantı gönder')->form([
            'forgot_password_form[email]' => 'same-email-limit@example.com',
        ]));
        self::assertResponseStatusCodeSame(429);
    }

    public function testValidTokenOpensFormAfterUrlCleaning(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-token@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-token@example.com');

        $client->request('GET', '/sifre-yenile/'.$token);
        self::assertResponseRedirects('/sifre-yenile');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Yeni parola');
        self::assertStringNotContainsString($token, $client->getRequest()->getUri());
    }

    public function testTamperedTokenRejected(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-tamper@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-tamper@example.com');
        $client->request('GET', '/sifre-yenile/'.$token.'x');
        self::assertResponseRedirects('/sifremi-unuttum');
    }

    public function testExpiredTokenRejectedWithoutSleep(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-expired@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-expired@example.com', lifetime: 0);

        $client->request('GET', '/sifre-yenile/'.$token);
        self::assertResponseRedirects('/sifremi-unuttum');
    }

    public function testSuccessfulResetUpdatesPasswordWithoutAutoLogin(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-ok@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $before = $this->reloadUser($client, 'reset-ok@example.com')->getPasswordChangedAt();
        $token = $this->generateTokenForEmail($client, 'reset-ok@example.com');

        $client->request('GET', '/sifre-yenile/'.$token);
        $client->followRedirect();
        $client->submit($client->getCrawler()->selectButton('Parolayı güncelle')->form([
            'reset_password_form[plainPassword][first]' => 'Yeni-Guclu-Parola-456!',
            'reset_password_form[plainPassword][second]' => 'Yeni-Guclu-Parola-456!',
        ]));
        self::assertResponseRedirects('/giris');
        $tokenStorage = $client->getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        self::assertNull($tokenStorage->getToken());

        $reloaded = $this->reloadUser($client, 'reset-ok@example.com');
        self::assertNotEquals($before, $reloaded->getPasswordChangedAt());
        self::assertStringNotContainsString('Yeni-Guclu-Parola-456!', $reloaded->getPassword());

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'reset-ok@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Giriş bilgileri hatalı');

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'reset-ok@example.com',
            '_password' => 'Yeni-Guclu-Parola-456!',
        ]));
        self::assertResponseRedirects('/hesabim');
    }

    public function testUsedTokenCannotBeReused(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-reuse@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-reuse@example.com');

        $client->request('GET', '/sifre-yenile/'.$token);
        $client->followRedirect();
        $client->submit($client->getCrawler()->selectButton('Parolayı güncelle')->form([
            'reset_password_form[plainPassword][first]' => 'Yeni-Guclu-Parola-789!',
            'reset_password_form[plainPassword][second]' => 'Yeni-Guclu-Parola-789!',
        ]));
        self::assertResponseRedirects('/giris');

        self::ensureKernelShutdown();
        $client2 = $this->newClient();
        $client2->request('GET', '/sifre-yenile/'.$token);
        self::assertResponseRedirects('/sifremi-unuttum');
    }

    public function testWeakAndMismatchedPasswordsRejected(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-weak@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-weak@example.com');

        $client->request('GET', '/sifre-yenile/'.$token);
        $client->followRedirect();
        $client->submit($client->getCrawler()->selectButton('Parolayı güncelle')->form([
            'reset_password_form[plainPassword][first]' => '123',
            'reset_password_form[plainPassword][second]' => '123',
        ]));
        self::assertResponseStatusCodeSame(422);

        $client->submit($client->getCrawler()->selectButton('Parolayı güncelle')->form([
            'reset_password_form[plainPassword][first]' => 'Yeni-Guclu-Parola-789!',
            'reset_password_form[plainPassword][second]' => 'Yeni-Guclu-Parola-000!',
        ]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testSuspendedAfterTokenCannotReset(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-later-susp@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-later-susp@example.com');

        $user = $this->reloadUser($client, 'reset-later-susp@example.com');
        /** @var UserRepository $users */
        $users = $client->getContainer()->get(UserRepository::class);
        $user->transitionTo(UserStatus::Suspended);
        $users->save($user);

        $client->request('GET', '/sifre-yenile/'.$token);
        self::assertResponseRedirects('/sifremi-unuttum');
    }

    public function testArchivedAfterTokenCannotReset(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-later-arch@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-later-arch@example.com');

        $user = $this->reloadUser($client, 'reset-later-arch@example.com');
        /** @var UserRepository $users */
        $users = $client->getContainer()->get(UserRepository::class);
        $user->transitionTo(UserStatus::Archived);
        $users->save($user);

        $client->request('GET', '/sifre-yenile/'.$token);
        self::assertResponseRedirects('/sifremi-unuttum');
    }

    public function testSameAsOldPasswordRejected(): void
    {
        $client = $this->newClient();
        $this->createUser($client, 'reset-same@example.com', 'Guclu-Parola-123!', UserStatus::Active);
        $token = $this->generateTokenForEmail($client, 'reset-same@example.com');

        $client->request('GET', '/sifre-yenile/'.$token);
        $client->followRedirect();
        $client->submit($client->getCrawler()->selectButton('Parolayı güncelle')->form([
            'reset_password_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'reset_password_form[plainPassword][second]' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'farklı olmalıdır');
    }

    private function newClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueIp());

        return $client;
    }

    private function uniqueIp(): string
    {
        ++self::$ipCounter;

        return \sprintf('198.51.100.%d', self::$ipCounter % 200);
    }

    private function generateTokenForEmail(KernelBrowser $client, string $email, int $lifetime = 3600): string
    {
        $user = $this->reloadUser($client, $email);
        /** @var ResetPasswordRequestRepository $repo */
        $repo = $client->getContainer()->get(ResetPasswordRequestRepository::class);
        $repo->removeRequests($user);
        $helper = $client->getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelper::class, $helper);

        return $helper->generateResetToken($user, $lifetime)->getToken();
    }

    private function reloadUser(KernelBrowser $client, string $email): User
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        /** @var UserRepository $users */
        $users = $client->getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail(mb_strtolower($email, 'UTF-8'));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function createUser(KernelBrowser $client, string $email, string $password, UserStatus $status): User
    {
        /** @var UserFactory $factory */
        $factory = $client->getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = $client->getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, $password, 'Reset', 'User', UserRole::Student);

        if (UserStatus::PendingVerification !== $status) {
            $lifecycle->markEmailVerifiedAndActivate($user);
            /** @var UserRepository $users */
            $users = $client->getContainer()->get(UserRepository::class);
            if (UserStatus::Suspended === $status) {
                $user->transitionTo(UserStatus::Suspended);
                $users->save($user);
            } elseif (UserStatus::Archived === $status) {
                $user->transitionTo(UserStatus::Archived);
                $users->save($user);
            }
        }

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
