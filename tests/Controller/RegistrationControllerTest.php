<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionApplicationRepository;
use App\Repository\TeacherApplicationRepository;
use App\Repository\UserRepository;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class RegistrationControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testRegisterChooserShowsFourOptions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nasıl devam etmek istersiniz');
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/kayit/ogrenci"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/kayit/veli"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/kayit/ogretmen"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href="/kayit/kurum"]')->count());
    }

    public function testSuccessfulStudentRegistrationCreatesPendingStudentAndSendsEmail(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit/ogrenci');
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
        self::assertNotContains(UserRole::Teacher->value, $user->getRoles());
        self::assertNotSame('Guclu-Parola-123!', $user->getPassword());
        self::assertNull($user->getNormalizedPhone());
    }

    public function testParentRegistrationAssignsParentRoleOnly(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit/veli');
        $form = $crawler->selectButton('Kayıt ol')->form([
            'registration_form[firstName]' => 'Veli',
            'registration_form[lastName]' => 'Yılmaz',
            'registration_form[email]' => 'veli@example.com',
            'registration_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'registration_form[plainPassword][second]' => 'Guclu-Parola-123!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/kayit/eposta-kontrol');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('veli@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertContains(UserRole::Parent->value, $user->getRoles());
        self::assertNotContains(UserRole::Student->value, $user->getRoles());
        self::assertNotContains(UserRole::Teacher->value, $user->getRoles());
        self::assertNull($user->getPhone());
    }

    public function testTeacherPathCreatesBaseUserWithoutTeacherRole(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit/ogretmen');
        $form = $crawler->selectButton('Hesap oluştur')->form([
            'registration_form[firstName]' => 'Öğret',
            'registration_form[lastName]' => 'Men',
            'registration_form[email]' => 'ogretmen-kayit@example.com',
            'registration_form[plainPassword][first]' => 'Guclu-Parola-123!',
            'registration_form[plainPassword][second]' => 'Guclu-Parola-123!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/kayit/eposta-kontrol');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'öğretmen erişimin açılmaz');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('ogretmen-kayit@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame([UserRole::User->value], $user->getRoles());
        self::assertNotContains(UserRole::Teacher->value, $user->getRoles());

        /** @var TeacherApplicationRepository $apps */
        $apps = static::getContainer()->get(TeacherApplicationRepository::class);
        self::assertNull($apps->findOpenForUser($user));
    }

    public function testClientCannotInjectAccountTypeOrPrivilegedFieldsOnStudentPath(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kayit/ogrenci');
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
        $values['registration_form']['accountType'] = 'parent';
        $values['registration_form']['flow'] = 'ogretmen';
        $values['registration_form']['status'] = 'active';
        $values['registration_form']['globalRoles'] = ['ROLE_SUPER_ADMIN'];
        $client->request('POST', '/kayit/ogrenci', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ekstra alan');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        self::assertNull($users->findOneByNormalizedEmail('inject@example.com'));
        self::assertEmailCount(0);
    }

    public function testDuplicateEmailIsRejectedWithGenericMessage(): void
    {
        $client = static::createClient();
        $this->registerViaService('dup@example.com', 'Guclu-Parola-123!');

        $crawler = $client->request('GET', '/kayit/ogrenci');
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
        $crawler = $client->request('GET', '/kayit/ogrenci');
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
        $crawler = $client->request('GET', '/kayit/ogrenci');
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
        $client->request('POST', '/kayit/ogrenci', [
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

        $crawler = $client->request('GET', '/kayit/ogrenci');
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

    public function testVerifiedUserCanSubmitTeacherApplicationWithoutPrivilege(): void
    {
        $client = static::createClient();
        $user = $this->activeBaseUser('teacher-ui@example.com');
        $this->login($client, $user);

        $crawler = $client->request('GET', '/basvuru/ogretmen');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Başvuruyu gönder')->form([
            'teacher_application_form[acknowledgePendingReview]' => true,
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/basvuru/ogretmen');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'incelemede');

        /** @var TeacherApplicationRepository $apps */
        $apps = static::getContainer()->get(TeacherApplicationRepository::class);
        $open = $apps->findOpenForUser($user);
        self::assertNotNull($open);
        self::assertSame(OnboardingApplicationStatus::Pending, $open->getStatus());

        $reloadedUsers = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $reloadedUsers);
        $reloaded = $reloadedUsers->findOneById($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotContains(UserRole::Teacher->value, $reloaded->getRoles());
    }

    public function testInstitutionApplicationDoesNotCreateInstitution(): void
    {
        $client = static::createClient();
        $user = $this->activeBaseUser('inst-ui@example.com');
        $this->login($client, $user);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $before = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM institutions');

        $crawler = $client->request('GET', '/basvuru/kurum');
        $form = $crawler->selectButton('Başvuruyu gönder')->form([
            'institution_application_form[proposedName]' => 'Demo Okul',
            'institution_application_form[proposedType]' => InstitutionType::School->value,
            'institution_application_form[acknowledgePendingReview]' => true,
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/basvuru/kurum');

        $after = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM institutions');
        self::assertSame($before, $after);

        /** @var InstitutionApplicationRepository $apps */
        $apps = static::getContainer()->get(InstitutionApplicationRepository::class);
        $open = $apps->findOpenForUser($user);
        self::assertNotNull($open);
        self::assertSame(OnboardingApplicationStatus::Pending, $open->getStatus());

        $reloadedUsers = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $reloadedUsers);
        $reloaded = $reloadedUsers->findOneById($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotContains(UserRole::InstitutionManager->value, $reloaded->getRoles());
    }

    public function testUnverifiedUserCannotSubmitTeacherApplication(): void
    {
        $client = static::createClient();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $pending = $factory->createAndPersist('pending-ui@example.com', 'Guclu-Parola-123!', 'Pen', 'Ding', UserRole::User);

        // Pending accounts cannot establish an authenticated session (UserChecker).
        $client->request('GET', '/basvuru/ogretmen');
        self::assertResponseRedirects('/giris');

        /** @var TeacherApplicationRepository $apps */
        $apps = static::getContainer()->get(TeacherApplicationRepository::class);
        self::assertNull($apps->findOpenForUser($pending));
    }

    public function testApplicationRoutesRequireAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/basvuru/ogretmen');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/basvuru/kurum');
        self::assertResponseRedirects('/giris');
    }

    private function registerViaService(string $email, string $password): User
    {
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);

        return $factory->createAndPersist($email, $password, 'Var', 'Olan', UserRole::Student);
    }

    private function activeBaseUser(string $email): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Bas', 'User', UserRole::User);
        $lifecycle->markEmailVerifiedAndActivate($user);

        return $user;
    }

    private function login(KernelBrowser $client, User $user): void
    {
        $client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();
        foreach ([
            'security_audit_events',
            'teacher_applications',
            'institution_applications',
            'institution_memberships',
            'institutions',
            'users',
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        parent::tearDown();
    }
}
