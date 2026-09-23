<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\CatalogWriteService;
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

        $this->createPrivileged('cat-admin-student@example.com', UserRole::Student);
        $client = static::createClient();
        $this->login($client, 'cat-admin-student@example.com');
        $client->request('GET', '/yonetim/mufredat');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCreateSubject(): void
    {
        $this->createPrivileged('cat-admin@example.com', UserRole::Admin);
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
        $this->createPrivileged('cat-admin-csrf@example.com', UserRole::Admin);
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

    private function createPrivileged(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Admin', 'User', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
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
