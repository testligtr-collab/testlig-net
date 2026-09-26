<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\StudentProfileRepository;
use App\Repository\UserRepository;
use App\Service\StudentProfileManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StudentOnboardingFlowTest extends WebTestCase
{
    public function testStudentFirstLoginRedirectsToOnboarding(): void
    {
        $client = static::createClient();
        $this->createActiveUser('first-login@example.com', UserRole::Student);

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'first-login@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseRedirects('/ogrenci/kurulum');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Seni biraz tanıyalım');
    }

    public function testCompletedOnboardingGoesToDashboardAndSkipsSetup(): void
    {
        $client = static::createClient();
        $user = $this->createActiveUser('done@example.com', UserRole::Student);
        $this->completeOnboarding($user, GradeLevel::Grade5);

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'done@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseRedirects('/ogrenci');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Merhaba, Ayşe');
        self::assertSelectorTextContains('body', '5. sınıf');
        self::assertSelectorTextContains('body', 'Henüz başladığın bir çalışma yok.');
        self::assertSelectorTextContains('body', 'Yakında');
        self::assertSelectorNotExists('body:contains("%")');
        self::assertSelectorNotExists('body:contains("rozet")');
        self::assertSelectorExists('a[href="/ogrenci"][aria-current="page"]');
        self::assertSelectorExists('a[href="/ogrenci/dersler"]');
        self::assertSelectorExists('a[href="/ogrenci/testler"]');
        self::assertSelectorExists('a[href="/ogrenci/testler/gecmisim"]');
        self::assertSelectorExists('a[href="/ogrenci/profil"]');
        self::assertSelectorExists('a[href="/hesabim"]');
        self::assertSelectorExists('form[action="/cikis"] input[name="_csrf_token"]');

        $client->request('GET', '/ogrenci/kurulum');
        self::assertResponseRedirects('/ogrenci');
    }

    public function testOnboardingAcceptsGradeBoundsAndOptionalFields(): void
    {
        $client = static::createClient();
        $this->loginStudent($client, 'bounds@example.com');

        $crawler = $client->request('GET', '/ogrenci/kurulum');
        $form = $crawler->selectButton('Profilimi tamamla')->form([
            'student_profile[gradeLevel]' => (string) GradeLevel::Grade1->value,
            'student_profile[schoolName]' => '',
            'student_profile[city]' => '',
            'student_profile[learningGoal]' => '',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ogrenci');

        $client = static::createClient();
        $this->loginStudent($client, 'bounds12@example.com');
        $crawler = $client->request('GET', '/ogrenci/kurulum');
        $client->submit($crawler->selectButton('Profilimi tamamla')->form([
            'student_profile[gradeLevel]' => (string) GradeLevel::Grade12->value,
            'student_profile[schoolName]' => 'Test Ortaokulu',
            'student_profile[city]' => 'Ankara',
            'student_profile[learningGoal]' => 'Matematik çalışmak',
        ]));
        self::assertResponseRedirects('/ogrenci');
        $client->followRedirect();
        self::assertSelectorTextContains('body', '12. sınıf');
    }

    public function testInvalidGradeLevelsRejected(): void
    {
        $client = static::createClient();
        $this->loginStudent($client, 'bad-grade@example.com');

        foreach (['0', '13'] as $invalid) {
            $crawler = $client->request('GET', '/ogrenci/kurulum');
            $form = $crawler->selectButton('Profilimi tamamla')->form();
            $form->disableValidation();
            $values = $form->getPhpValues();
            $values['student_profile']['gradeLevel'] = $invalid;
            $client->request($form->getMethod(), $form->getUri(), $values);
            self::assertTrue(
                $client->getResponse()->isSuccessful() || 422 === $client->getResponse()->getStatusCode(),
                'Invalid grade should not complete onboarding.',
            );
            self::assertSelectorExists('form[name="student_profile"]');
            /** @var StudentProfileRepository $profiles */
            $profiles = static::getContainer()->get(StudentProfileRepository::class);
            /** @var UserRepository $users */
            $users = static::getContainer()->get(UserRepository::class);
            $user = $users->findOneByNormalizedEmail('bad-grade@example.com');
            self::assertInstanceOf(User::class, $user);
            self::assertNull($profiles->findOneByUser($user));
        }
    }

    public function testTooLongOptionalFieldsRejected(): void
    {
        $client = static::createClient();
        $this->loginStudent($client, 'toolong@example.com');

        $crawler = $client->request('GET', '/ogrenci/kurulum');
        $client->submit($crawler->selectButton('Profilimi tamamla')->form([
            'student_profile[gradeLevel]' => (string) GradeLevel::Grade3->value,
            'student_profile[schoolName]' => str_repeat('a', 161),
            'student_profile[city]' => str_repeat('b', 101),
            'student_profile[learningGoal]' => str_repeat('c', 501),
        ]));
        self::assertTrue(
            $client->getResponse()->isSuccessful() || 422 === $client->getResponse()->getStatusCode(),
        );
        self::assertSelectorExists('#student_profile_schoolName_error1, .form-errors, ul li');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('toolong@example.com');
        self::assertInstanceOf(User::class, $user);
        /** @var StudentProfileRepository $profiles */
        $profiles = static::getContainer()->get(StudentProfileRepository::class);
        self::assertNull($profiles->findOneByUser($user));
    }

    public function testDoubleSubmitDoesNotCreateSecondProfile(): void
    {
        $client = static::createClient();
        $user = $this->loginStudent($client, 'double@example.com');

        $crawler = $client->request('GET', '/ogrenci/kurulum');
        $form = $crawler->selectButton('Profilimi tamamla')->form([
            'student_profile[gradeLevel]' => (string) GradeLevel::Grade4->value,
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/ogrenci');

        $client->request('GET', '/ogrenci/kurulum');
        self::assertResponseRedirects('/ogrenci');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(StudentProfile::class, 'p')
            ->andWhere('IDENTITY(p.user) = :uid')
            ->setParameter('uid', $user->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(1, $count);
    }

    public function testAnonymousAndNonStudentBlocked(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ogrenci');
        self::assertResponseRedirects('/giris');

        $client->request('GET', '/ogrenci/kurulum');
        self::assertResponseRedirects('/giris');

        $this->createActiveUser('parent@example.com', UserRole::Parent);
        // Fresh client so anonymous /ogrenci* visits do not leave a student target_path.
        $client = static::createClient();
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'parent@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseRedirects('/hesabim');

        $client->request('GET', '/ogrenci');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/ogrenci/kurulum');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/ogrenci/profil');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStudentCannotMutateAnotherStudentsProfile(): void
    {
        $client = static::createClient();
        $owner = $this->createActiveUser('owner@example.com', UserRole::Student);
        $this->completeOnboarding($owner, GradeLevel::Grade6, schoolName: 'Sahip Okulu');

        $intruder = $this->createActiveUser('intruder@example.com', UserRole::Student);
        $this->completeOnboarding($intruder, GradeLevel::Grade7, schoolName: 'İzinsiz Okul');

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'intruder@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();

        $crawler = $client->request('GET', '/ogrenci/profil');
        self::assertSelectorExists('input[name="student_profile[schoolName]"]');
        $schoolInput = $crawler->filter('input[name="student_profile[schoolName]"]');
        self::assertSame('İzinsiz Okul', $schoolInput->attr('value'));

        $client->submit($crawler->selectButton('Kaydet')->form([
            'student_profile[gradeLevel]' => (string) GradeLevel::Grade8->value,
            'student_profile[schoolName]' => 'Hack Okulu',
        ]));
        self::assertResponseRedirects('/ogrenci/profil');

        /** @var StudentProfileRepository $profiles */
        $profiles = static::getContainer()->get(StudentProfileRepository::class);
        $ownerProfile = $profiles->findOneByUser($owner);
        self::assertInstanceOf(StudentProfile::class, $ownerProfile);
        self::assertSame('Sahip Okulu', $ownerProfile->getSchoolName());
        self::assertSame(GradeLevel::Grade6, $ownerProfile->getGradeLevel());

        $intruderProfile = $profiles->findOneByUser($intruder);
        self::assertInstanceOf(StudentProfile::class, $intruderProfile);
        self::assertSame('Hack Okulu', $intruderProfile->getSchoolName());
    }

    public function testCsrfMissingRejected(): void
    {
        $client = static::createClient();
        $this->loginStudent($client, 'csrf@example.com');
        $client->request('GET', '/ogrenci/kurulum');

        $client->request('POST', '/ogrenci/kurulum', [
            'student_profile' => [
                'gradeLevel' => (string) GradeLevel::Grade2->value,
                '_token' => 'invalid',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail('csrf@example.com');
        self::assertInstanceOf(User::class, $user);
        /** @var StudentProfileRepository $profiles */
        $profiles = static::getContainer()->get(StudentProfileRepository::class);
        self::assertNull($profiles->findOneByUser($user));
    }

    public function testSafeTargetPathStillHonoured(): void
    {
        $this->createActiveUser('target@example.com', UserRole::Student);
        $client = static::createClient();
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
        $session = $client->getRequest()->getSession();
        $session->set('_security.main.target_path', '/hesabim');
        $session->save();

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => 'target@example.com',
            '_password' => 'Guclu-Parola-123!',
        ]));
        self::assertResponseRedirects('/hesabim');
    }

    private function loginStudent(KernelBrowser $client, string $email): User
    {
        $user = $this->createActiveUser($email, UserRole::Student);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();

        return $user;
    }

    private function createActiveUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ayşe', 'Yılmaz', $role);
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $lifecycle->markEmailVerifiedAndActivate($user);
        }
        self::ensureKernelShutdown();

        return $user;
    }

    private function completeOnboarding(
        User $user,
        GradeLevel $grade,
        ?string $schoolName = null,
    ): void {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $fresh = $users->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        /** @var StudentProfileManager $manager */
        $manager = static::getContainer()->get(StudentProfileManager::class);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;
        $dto->schoolName = $schoolName;
        $manager->completeOnboarding($fresh, $dto);
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $sm = $em->getConnection()->createSchemaManager();
            if ($sm->tablesExist(['student_profiles'])) {
                $em->getConnection()->executeStatement('DELETE FROM student_profiles');
            }
            if ($sm->tablesExist(['users'])) {
                $em->getConnection()->executeStatement('DELETE FROM users');
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
