<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogUnit;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\CatalogLoginReturn;
use App\Service\CatalogWriteService;
use App\Service\StudentProfileManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

final class CatalogLoginReturnTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private const NOTICE = '[data-login-resume]';

    /** @var array{subject: string, unit: string, topic: string, draftUnit: string, unitId: string, unitName: string, topicName: string} */
    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->catalog = $this->createCatalog();
    }

    public function testUnitPageLinksCarryOnlyCatalogCoordinates(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $this->publicUnit());
        self::assertResponseIsSuccessful();

        self::assertSame('/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit'], $crawler->filter('aside.catalog-cta a.btn--ghost')->attr('href'));
        self::assertSame(
            '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit'].'&konu='.$this->catalog['topic'],
            $crawler->filter('.catalog-list a.btn--ghost')->first()->attr('href'),
        );
        foreach ($crawler->filter('a.btn--cta')->extract(['href']) as $register) {
            self::assertSame('/kayit', $register);
        }
    }

    public function testStudentFromUnitCtaReturnsToTheUnitExactlyOnce(): void
    {
        $this->createStudent('catret-unit@example.com', GradeLevel::Grade1);

        $client = static::createClient();
        $crawler = $client->request('GET', $this->publicUnit());
        $client->click($crawler->filter('aside.catalog-cta a.btn--ghost')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(self::NOTICE);

        // A wrong password keeps the form usable and the remembered unit.
        $client->submit($client->getCrawler()->selectButton('Giriş yap')->form([
            '_username' => 'catret-unit@example.com',
            '_password' => 'yanlis-parola',
        ]));
        self::assertResponseRedirects('/giris');
        $crawler = $client->followRedirect();
        self::assertSelectorExists('.flash-error');
        self::assertSelectorExists(self::NOTICE);

        $this->submit($client, $crawler, 'catret-unit@example.com');
        self::assertResponseRedirects($this->studentUnit());
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $this->catalog['unitName']);
        self::assertSelectorTextContains('title', $this->catalog['unitName']);
        self::assertFalse($client->getRequest()->getSession()->has(CatalogLoginReturn::SESSION_KEY));

        // A later independent login (new session) goes to the normal student landing.
        $other = $this->newClient();
        $this->plainLogin($other, 'catret-unit@example.com');
        self::assertResponseRedirects('/ogrenci');
    }

    public function testTopicCtaReturnsToTheTopic(): void
    {
        $this->createStudent('catret-topic@example.com', GradeLevel::Grade1);

        $client = static::createClient();
        $crawler = $client->request('GET', $this->publicUnit());
        $client->click($crawler->filter('.catalog-list a.btn--ghost')->first()->link());
        $this->submit($client, $client->getCrawler(), 'catret-topic@example.com');
        self::assertResponseRedirects($this->studentUnit().'/'.$this->catalog['topic']);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $this->catalog['topicName']);
    }

    public function testDirectLoginKeepsTheDefaultLandingAndDiscardsAnOldUnit(): void
    {
        $this->createStudent('catret-direct@example.com', GradeLevel::Grade1);

        $client = static::createClient();
        $this->plainLogin($client, 'catret-direct@example.com');
        self::assertResponseRedirects('/ogrenci');

        // The unit was remembered, but the visitor then opened /giris on its own: independent login.
        $client = $this->newClient();
        $client->request('GET', '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit']);
        self::assertSelectorExists(self::NOTICE);
        $crawler = $client->request('GET', '/giris');
        self::assertSelectorNotExists(self::NOTICE);
        $this->submit($client, $crawler, 'catret-direct@example.com');
        self::assertResponseRedirects('/ogrenci');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTargets(): iterable
    {
        yield 'external url as unit' => ['sinif=1&ders=matematik&unite=https://evil.example/x'];
        yield 'protocol relative' => ['sinif=1&ders=//evil.example&unite=x'];
        yield 'path traversal' => ['sinif=1&ders=..%2F..%2Fyonetim&unite=x'];
        yield 'array value' => ['sinif=1&ders[]=a&unite[]=b'];
        yield 'unknown grade' => ['sinif=99&ders=matematik&unite=x'];
        yield 'unknown unit' => ['sinif=1&ders=matematik&unite=yok-boyle-bir-unite'];
        yield 'missing subject' => ['sinif=1&unite=x'];
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTargetsFallBackToTheDefaultLanding(string $query): void
    {
        $email = 'catret-invalid-'.substr(md5($query), 0, 8).'@example.com';
        $this->createStudent($email, GradeLevel::Grade1);

        $client = static::createClient();
        $crawler = $client->request('GET', '/giris?'.$query);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::NOTICE);
        $this->submit($client, $crawler, $email);
        self::assertResponseRedirects('/ogrenci');
    }

    public function testUnpublishedUnitsAndUnknownTopicsAreNeverRemembered(): void
    {
        $this->createStudent('catret-draft@example.com', GradeLevel::Grade1);

        $client = static::createClient();
        $client->request('GET', '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['draftUnit']);
        self::assertSelectorNotExists(self::NOTICE);

        $client->request('GET', '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit'].'&konu=yok-boyle-bir-konu');
        self::assertSelectorNotExists(self::NOTICE);
    }

    public function testUnitUnpublishedAfterTheClickFallsBackToTheLanding(): void
    {
        $this->createStudent('catret-late@example.com', GradeLevel::Grade1);

        $client = static::createClient();
        $crawler = $client->request('GET', $this->publicUnit());
        $crawler = $client->click($crawler->filter('aside.catalog-cta a.btn--ghost')->link());
        self::assertSelectorExists(self::NOTICE);

        $writer = static::getContainer()->get(CatalogWriteService::class);
        self::assertInstanceOf(CatalogWriteService::class, $writer);
        $writer->archiveUnit(Uuid::fromString($this->catalog['unitId']));

        $this->submit($client, $crawler, 'catret-late@example.com');
        self::assertResponseRedirects('/ogrenci');
    }

    public function testStudentOfAnotherGradeOrWithoutOnboardingGetsTheirOwnLanding(): void
    {
        $this->createStudent('catret-grade2@example.com', GradeLevel::Grade2);
        $this->createUser('catret-fresh@example.com', UserRole::Student);

        foreach (['catret-grade2@example.com' => '/ogrenci', 'catret-fresh@example.com' => '/ogrenci/kurulum'] as $email => $landing) {
            $client = $this->newClient();
            $client->request('GET', '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit']);
            self::assertSelectorExists(self::NOTICE);
            $this->submit($client, $client->getCrawler(), $email);
            self::assertResponseRedirects($landing);
        }
    }

    public function testOtherRolesKeepTheirOwnLandingEvenWithARememberedUnit(): void
    {
        $this->createUser('catret-parent@example.com', UserRole::Parent);
        $this->createUser('catret-teacher@example.com', UserRole::Teacher);
        $this->createUser('catret-admin@example.com', UserRole::Admin);

        foreach ([
            'catret-parent@example.com' => '/veli',
            'catret-teacher@example.com' => '/calisma-alani',
            'catret-admin@example.com' => '/yonetim',
        ] as $email => $landing) {
            $client = $this->newClient();
            $client->request('GET', '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit']);
            $this->submit($client, $client->getCrawler(), $email);
            self::assertResponseRedirects($landing);
        }
    }

    public function testSignedInStudentOpeningTheLinkReachesTheUnitAndOthersGoHome(): void
    {
        $this->createStudent('catret-signed@example.com', GradeLevel::Grade1);
        $this->createUser('catret-plain@example.com', UserRole::User);

        $client = static::createClient();
        $this->plainLogin($client, 'catret-signed@example.com');
        $client->request('GET', '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit']);
        self::assertResponseRedirects($this->studentUnit());
        $client->request('GET', '/giris');
        self::assertResponseRedirects('/hesabim');

        $client = $this->newClient();
        $this->plainLogin($client, 'catret-plain@example.com');
        $client->request('GET', '/giris?sinif=1&ders='.$this->catalog['subject'].'&unite='.$this->catalog['unit']);
        self::assertResponseRedirects('/hesabim');
    }

    public function testStudentRoutesStayProtectedForVisitorsAndOtherRoles(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->studentUnit());
        self::assertResponseRedirects('/giris');

        $this->createUser('catret-parent-guard@example.com', UserRole::Parent);
        $client = $this->newClient();
        $this->plainLogin($client, 'catret-parent-guard@example.com');
        $client->request('GET', $this->studentUnit());
        self::assertResponseStatusCodeSame(403);
    }

    private function publicUnit(): string
    {
        return '/dersler/1/'.$this->catalog['subject'].'/'.$this->catalog['unit'];
    }

    private function studentUnit(): string
    {
        return '/ogrenci/dersler/'.$this->catalog['subject'].'/'.$this->catalog['unit'];
    }

    private function submit(KernelBrowser $client, Crawler $crawler, string $email): void
    {
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function plainLogin(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $this->submit($client, $crawler, $email);
    }

    private function newClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function createStudent(string $email, GradeLevel $grade): void
    {
        $user = $this->createUser($email, UserRole::Student);
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $fresh = $users->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        self::assertInstanceOf(StudentProfileManager::class, $profiles);
        $request = new StudentProfileRequest();
        $request->gradeLevel = $grade;
        $profiles->completeOnboarding($fresh, $request);
        self::ensureKernelShutdown();
    }

    private function createUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Min', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);
        self::ensureKernelShutdown();

        return $user;
    }

    /**
     * @return array{subject: string, unit: string, topic: string, draftUnit: string, unitId: string, unitName: string, topicName: string}
     */
    private function createCatalog(): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $writer = static::getContainer()->get(CatalogWriteService::class);
        self::assertInstanceOf(CatalogWriteService::class, $writer);
        $subject = $writer->createSubject(GradeLevel::Grade1, 'Matematik', null, 1, 'matematik');
        $writer->publishSubject($subject->getId());
        $unit = $writer->createUnit($subject->getId(), 'Sayılar ve Nicelikler', null, 0);
        $writer->publishUnit($unit->getId());
        $topic = $writer->createTopic($unit->getId(), 'Toplama işlemi', 'Özet', 0, 15);
        $writer->publishTopic($topic->getId());
        $draft = $writer->createUnit($subject->getId(), 'Taslak Ünite', null, 1);
        self::assertInstanceOf(CatalogSubject::class, $subject);
        self::assertInstanceOf(CatalogUnit::class, $unit);
        self::assertInstanceOf(CatalogTopic::class, $topic);
        $result = [
            'subject' => $subject->getSlug(),
            'unit' => $unit->getSlug(),
            'topic' => $topic->getSlug(),
            'draftUnit' => $draft->getSlug(),
            'unitId' => $unit->getId()->toRfc4122(),
            'unitName' => $unit->getName(),
            'topicName' => $topic->getName(),
        ];
        self::ensureKernelShutdown();

        return $result;
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            $sm = $conn->createSchemaManager();
            if ($sm->tablesExist(['student_profiles', 'users'])) {
                $conn->executeStatement("DELETE FROM student_profiles WHERE user_id IN (SELECT id FROM users WHERE normalized_email LIKE 'catret-%@example.com')");
            }
            if ($sm->tablesExist(['users'])) {
                $conn->executeStatement("DELETE FROM users WHERE normalized_email LIKE 'catret-%@example.com'");
            }
            foreach (['catalog_topics', 'catalog_units', 'catalog_subjects'] as $table) {
                if ($sm->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
        } catch (\Throwable) {
        }
        self::ensureKernelShutdown();
    }
}
