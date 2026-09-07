<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class RegistrationControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testRegisterPageIsReachable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/kayit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hesap oluştur');
    }

    public function testSuccessfulRegistrationCreatesPendingStudentAndSendsEmail(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit');
        $form = $crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Ayşe',
            'registration_form[lastName]' => 'Yılmaz',
            'registration_form[email]' => 'Ogrenci@Example.COM',
            'registration_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'registration_form[plainPassword][second]' => 'Guclu-Parola-123!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/kayit/eposta-kontrol');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailHeaderSame($email, 'To', 'Ogrenci@Example.COM');
        self::assertEmailHtmlBodyContains($email, 'doğrula');

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'E-postanı kontrol et');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('ogrenci@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        self::assertContains(UserRole::Student->value, $user->getRoles());
        self::assertNotSame('Guclu-Parola-123!', $user->getPassword());
    }

    public function testDuplicateEmailIsRejectedWithGenericMessage(): void
    {
        $client = static::createClient();
        $this->registerViaService('dup@example.com', 'Guclu-Parola-123!');

        $crawler = $client->request('GET', '/kayit');
        $form = $crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'İkinci',
            'registration_form[lastName]' => 'Kullanıcı',
            'registration_form[email]' => 'DUP@example.com',
            'registration_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'registration_form[plainPassword][second]' => 'Guclu-Parola-123!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.flash-error');
        self::assertSelectorNotExists('body:contains("already exists")');
    }

    public function testWeakPasswordAndMissingTermsAreRejected(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit');
        $form = $crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Zayıf',
            'registration_form[lastName]' => 'Parola',
            'registration_form[email]' => 'zayif@example.com',
            'registration_form[plainPassword][first]' => '123',
            'registration_form[plainPassword][second]' => '123',
            'registration_form[agreeTerms]' => false,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Parola');
        self::assertSelectorTextContains('body', 'kabul');
    }

    public function testMismatchedPasswordsRejected(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit');
        $form = $crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Ali',
            'registration_form[lastName]' => 'Veli',
            'registration_form[email]' => 'mismatch@example.com',
            'registration_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'registration_form[plainPassword][second]' => 'Guclu-Parola-999!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'eşleşmiyor');
    }

    public function testInvalidCsrfIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/kayit', [
            'registration_form' => [
                'firstName' => 'Csrf',
                'lastName' => 'Test',
                'email' => 'csrf@example.com',
                'plainPassword' => ['first' => 'Guclu-Parola-123!', 'second' => 'Guclu-Parola-123!'],
                'agreeTerms' => true,
                '_token' => 'invalid',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testClientCannotInjectRoleOrStatusFields(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit');
        $form = $crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Hack',
            'registration_form[lastName]' => 'Attempt',
            'registration_form[email]' => 'inject@example.com',
            'registration_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'registration_form[plainPassword][second]' => 'Guclu-Parola-123!',
            'registration_form[agreeTerms]' => true,
        ]);
        $values = $form->getPhpValues();
        $values['registration_form']['roles'] = ['ROLE_ADMIN'];
        $values['registration_form']['status'] = 'active';
        $values['registration_form']['globalRoles'] = ['ROLE_SUPER_ADMIN'];
        $values['registration_form']['emailVerifiedAt'] = '2020-01-01T00:00:00+00:00';
        $client->request('POST', '/kayit', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ekstra alan');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        self::assertNull($users->findOneByNormalizedEmail('inject@example.com'));
        self::assertEmailCount(0);
    }

    public function testMailerTransportFailureStillRedirectsWithoutHttp500(): void
    {
        $client = static::createClient();

        $failingSender = new class implements \App\Service\EmailVerificationSenderInterface {
            public function sendVerificationEmail(User $user): void
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('SMTP unavailable');
            }

            public function requestResend(string $email): void
            {
            }
        };
        static::getContainer()->set(\App\Service\EmailVerificationSenderInterface::class, $failingSender);

        $crawler = $client->request('GET', '/kayit');
        $form = $crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Mail',
            'registration_form[lastName]' => 'Fail',
            'registration_form[email]' => 'mail-fail@example.com',
            'registration_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'registration_form[plainPassword][second]' => 'Guclu-Parola-123!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/kayit/eposta-kontrol');
        self::assertResponseStatusCodeSame(302);

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('mail-fail@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        self::assertNull($user->getEmailVerifiedAt());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'E-postanı kontrol et');
        self::assertSelectorNotExists('body:contains("SMTP")');
        self::assertSelectorNotExists('body:contains("unavailable")');
    }

    private function registerViaService(string $email, string $password): User
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);

        return $factory->createAndPersist($email, $password, 'Var', 'Olan', UserRole::Student);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
            $em->getConnection()->executeStatement('DELETE FROM users');
        }
        parent::tearDown();
    }
}
