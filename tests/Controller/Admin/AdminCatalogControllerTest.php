<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Service\CatalogWriteService;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCatalogControllerTest extends WebTestCase
{
    public function testAnonymousAndStudentDenied(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetim/mufredat');
        self::assertResponseRedirects('/giris');

        $this->createActive('cat-admin-student@example.com', UserRole::Student);
        $client = static::createClient();
        $this->login($client, 'cat-admin-student@example.com');
        $client->request('GET', '/yonetim/mufredat');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCreateSubject(): void
    {
        $this->createActive('cat-admin@example.com', UserRole::Admin);
        $client = static::createClient();
        $this->login($client, 'cat-admin@example.com');

        $crawler = $client->request('GET', '/yonetim/mufredat/ders/yeni');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Kaydet')->form([
            'catalog_subject[gradeLevel]' => (string) GradeLevel::Grade9->value,
            'catalog_subject[name]' => 'Fizik',
            'catalog_subject[position]' => '1',
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Fizik');
        self::assertSelectorTextContains('body', 'Taslak');
    }

    public function testPublishRequiresValidCsrf(): void
    {
        $this->createActive('cat-admin-csrf@example.com', UserRole::Admin);
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var CatalogWriteService $writer */
        $writer = static::getContainer()->get(CatalogWriteService::class);
        $subject = $writer->createSubject(GradeLevel::Grade10, 'Kimya', null, 1);
        $id = $subject->getId()->toRfc4122();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $this->login($client, 'cat-admin-csrf@example.com');
        $client->request('POST', '/yonetim/mufredat/ders/'.$id.'/yayimla', [
            '_token' => 'bad',
        ]);
        self::assertResponseStatusCodeSame(403);
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
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Admin', 'User', $role);
        $lifecycle->markEmailVerifiedAndActivate($user);
        self::ensureKernelShutdown();

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            $sm = $conn->createSchemaManager();
            foreach (['catalog_topics', 'catalog_units', 'catalog_subjects', 'users'] as $table) {
                if ($sm->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
