<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserListPresentationTest extends WebTestCase
{
    public function testAnonymousAndTeacherCannotOpenTheUserList(): void
    {
        $anonymous = $this->browser();
        $anonymous->request('GET', '/yonetim/kullanicilar');
        self::assertResponseRedirects('/giris');

        $teacher = $this->browser();
        $this->login($teacher, 'user_list_view_teacher@example.com', UserRole::Teacher, 'Deneme', 'Ogretmen');
        $teacher->request('GET', '/yonetim/kullanicilar');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSummaryIgnoresFiltersAndListKeepsRealRows(): void
    {
        $this->persistUser(
            'user_list_view_pending@example.com',
            UserRole::Student,
            'Bekleyen',
            'Ogrenci',
            UserStatus::PendingVerification,
            false,
        );
        $this->persistUser(
            'user_list_view_suspended@example.com',
            UserRole::Teacher,
            'Askidaki',
            'Ogretmen',
            UserStatus::Suspended,
            true,
        );
        $longFirst = str_repeat('Uzunad', 8);
        $longLast = str_repeat('Soyad', 8);
        $longEmail = 'user_list_view_long_'.str_repeat('a', 24).'@example.com';
        $this->persistUser($longEmail, UserRole::Teacher, $longFirst, $longLast, UserStatus::Active, true, [UserRole::Parent]);
        $client = $this->browser();
        $this->login($client, 'user_list_view_admin@example.com', UserRole::Admin, 'Yonetici', 'Hesap');

        $client->request('GET', '/yonetim/kullanicilar');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Kullanıcılar');
        self::assertSelectorTextContains('body', 'Kullanıcı hesaplarını ve erişim durumlarını yönet.');
        $open = $this->summary($client);
        $this->assertSummaryMatchesDatabase($open);

        $client->request('GET', '/yonetim/kullanicilar', ['q' => 'user_list_view_pending@example.com']);
        self::assertResponseIsSuccessful();
        self::assertSame($open, $this->summary($client));
        self::assertSelectorTextContains('.admin-user-list__count', '1 kullanıcı');
        self::assertSelectorTextContains('body', 'user_list_view_pending@example.com');
        self::assertSelectorTextContains('body', 'Doğrulama bekliyor');
        self::assertSelectorTextContains('body', 'Bekliyor');
        self::assertSelectorExists('a.admin-user-detail');

        $client->request('GET', '/yonetim/kullanicilar', ['q' => $longEmail, 'status' => 'active', 'role' => UserRole::Parent->value, 'verified' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame($open, $this->summary($client));
        self::assertSelectorExists('#user-filter-status option[value="active"][selected]');
        self::assertSelectorExists('#user-filter-verified option[value="1"][selected]');
        self::assertSelectorExists('#user-filter-role option[value="'.UserRole::Parent->value.'"][selected]');
        self::assertSelectorTextContains('body', $longFirst);
        self::assertSelectorTextContains('body', $longLast);
        self::assertSelectorTextContains('body', $longEmail);
        self::assertSelectorTextContains('body', 'Öğretmen');
        self::assertSelectorTextContains('body', 'Veli');
        self::assertSelectorTextContains('body', 'Ref ');
        self::assertSelectorExists('td.admin-user-person .admin-user-person__layout');
        self::assertSelectorExists('td.admin-user-date .admin-user-date__stack');
        self::assertSelectorExists('a.admin-user-clear[href="/yonetim/kullanicilar"]');
        $detail = $client->getCrawler()->filter('a.admin-user-detail')->attr('href');
        self::assertIsString($detail);
        self::assertStringStartsWith('/yonetim/kullanicilar/', $detail);

        $client->request('GET', '/yonetim/kullanicilar', ['q' => 'user_list_view_suspended@example.com']);
        self::assertResponseIsSuccessful();
        self::assertSame($open, $this->summary($client));
        self::assertSelectorExists('.admin-status--danger');
        self::assertSelectorTextContains('body', 'Askıda');

        $client->request('GET', '/yonetim/kullanicilar', ['q' => 'user_list_view_missing_token']);
        self::assertResponseIsSuccessful();
        self::assertSame($open, $this->summary($client));
        self::assertSelectorTextContains('.admin-user-list__count', '0 kullanıcı');
        self::assertSelectorTextContains('body', 'Filtrelere uygun kullanıcı bulunamadı.');
        self::assertSelectorNotExists('a.admin-user-detail');
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    /**
     * @param list<UserRole> $extraRoles
     */
    private function persistUser(
        string $email,
        UserRole $role,
        string $firstName,
        string $lastName,
        UserStatus $status,
        bool $verified,
        array $extraRoles = [],
    ): void {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', $firstName, $lastName, $role);
        if ($verified) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-02-02 00:00:00'));
        }
        if (UserStatus::PendingVerification !== $status) {
            if (UserStatus::Suspended === $status) {
                $user->transitionTo(UserStatus::Active);
            }
            $user->transitionTo($status);
        }
        foreach ($extraRoles as $extraRole) {
            $user->addGlobalRole($extraRole);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        $this->closeDatabase();
        self::ensureKernelShutdown();
    }

    private function login(KernelBrowser $client, string $email, UserRole $role, string $firstName, string $lastName): void
    {
        $this->persistUser($email, UserRole::Teacher, $firstName, $lastName, UserStatus::Active, true);
        if (UserRole::Teacher === $role) {
            $crawler = $client->request('GET', '/giris');
            $client->submit($crawler->selectButton('Giriş yap')->form([
                '_username' => $email,
                '_password' => 'Guclu-Parola-123!',
            ]));
            $client->followRedirect();

            return;
        }

        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail(strtolower($email));
        self::assertNotNull($user);
        $user->addGlobalRole($role);
        $users->save($user);
        $this->closeDatabase();
        self::ensureKernelShutdown();

        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    /**
     * @return array{total: string, active: string, pending: string, suspended: string}
     */
    private function summary(KernelBrowser $client): array
    {
        $crawler = $client->getCrawler();

        return [
            'total' => $crawler->filter('[data-user-summary="total"]')->text(),
            'active' => $crawler->filter('[data-user-summary="active"]')->text(),
            'pending' => $crawler->filter('[data-user-summary="pending"]')->text(),
            'suspended' => $crawler->filter('[data-user-summary="suspended"]')->text(),
        ];
    }

    /**
     * @param array{total: string, active: string, pending: string, suspended: string} $shown
     */
    private function assertSummaryMatchesDatabase(array $shown): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $rows = $em->getConnection()->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN status = 'pending_verification' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count
             FROM users",
        );
        self::assertIsArray($rows);
        self::assertSame((int) $rows['total'], (int) $shown['total']);
        self::assertSame((int) $rows['active_count'], (int) $shown['active']);
        self::assertSame((int) $rows['pending_count'], (int) $shown['pending']);
        self::assertSame((int) $rows['suspended_count'], (int) $shown['suspended']);
        $this->closeDatabase();
        self::ensureKernelShutdown();
    }

    private function closeDatabase(): void
    {
        if (!static::$booted) {
            return;
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em instanceof EntityManagerInterface && $em->getConnection()->isConnected()) {
            $em->getConnection()->close();
        }
    }

    protected function tearDown(): void
    {
        $this->closeDatabase();
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'user_list_view_%@example.com'",
                );
            }
            $this->closeDatabase();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
