<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use App\Service\CatalogWriteService;
use App\Service\StudentProfileManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StudentCourseCatalogControllerTest extends WebTestCase
{
    public function testAnonymousRedirected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ogrenci/dersler');
        self::assertResponseRedirects('/giris');
    }

    public function testParentForbidden(): void
    {
        $this->createActive('catalog-parent@example.com', UserRole::Parent);
        $client = static::createClient();
        $this->login($client, 'catalog-parent@example.com');
        $client->request('GET', '/ogrenci/dersler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyCatalogHonestMessage(): void
    {
        $user = $this->createActive('catalog-empty@example.com', UserRole::Student);
        $this->completeOnboarding($user, GradeLevel::Grade8);
        $client = static::createClient();
        $this->login($client, 'catalog-empty@example.com');
        $client->request('GET', '/ogrenci/dersler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sınıfın için henüz yayımlanmış ders bulunmuyor.');
        self::assertSelectorNotExists('body:contains("%")');
    }

    public function testStudentSeesOnlyOwnGradePublishedHierarchy(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogWriteService $writer */
        $writer = static::getContainer()->get(CatalogWriteService::class);
        $math5 = $writer->createSubject(GradeLevel::Grade5, 'Matematik Beş', null, 1);
        $writer->publishSubject($math5->getId());
        $unit = $writer->createUnit($math5->getId(), 'Kesirler', null, 0);
        $writer->publishUnit($unit->getId());
        $topic = $writer->createTopic($unit->getId(), 'Basit kesir', 'Özet', 0, 20);
        $writer->publishTopic($topic->getId());

        $writer->createSubject(GradeLevel::Grade5, 'Gizli Taslak', null, 2);
        $math6 = $writer->createSubject(GradeLevel::Grade6, 'Matematik Altı', null, 1);
        $writer->publishSubject($math6->getId());
        self::ensureKernelShutdown();

        $user = $this->createActive('catalog-grade5@example.com', UserRole::Student);
        $this->completeOnboarding($user, GradeLevel::Grade5);
        $client = static::createClient();
        $this->login($client, 'catalog-grade5@example.com');

        $client->request('GET', '/ogrenci/dersler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Matematik Beş');
        self::assertSelectorNotExists('body:contains("Gizli Taslak")');
        self::assertSelectorNotExists('body:contains("Matematik Altı")');

        $client->request('GET', '/ogrenci/dersler/matematik-bes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Kesirler');

        $client->request('GET', '/ogrenci/dersler/matematik-bes/kesirler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Basit kesir');
        self::assertSelectorTextContains('body', '20 dk');

        $client->request('GET', '/ogrenci/dersler/matematik-alti');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/ogrenci');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/ogrenci/dersler"]');
        self::assertSelectorNotExists('.student-quick-card--soon:contains("Dersler")');
    }

    public function testDashboardLinkWorks(): void
    {
        $user = $this->createActive('catalog-dash@example.com', UserRole::Student);
        $this->completeOnboarding($user, GradeLevel::Grade3);
        $client = static::createClient();
        $this->login($client, 'catalog-dash@example.com');
        $client->request('GET', '/ogrenci');
        $link = $client->getCrawler()->selectLink('Dersler')->link();
        $client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dersler');
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    private function createActive(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ayşe', 'Yılmaz', $role);
        $lifecycle->markEmailVerifiedAndActivate($user);
        self::ensureKernelShutdown();

        return $user;
    }

    private function completeOnboarding(User $user, GradeLevel $grade): void
    {
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
        $manager->completeOnboarding($fresh, $dto);
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            $sm = $conn->createSchemaManager();
            foreach (['catalog_topics', 'catalog_units', 'catalog_subjects', 'student_profiles', 'users'] as $table) {
                if ($sm->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
