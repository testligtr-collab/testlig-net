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

final class AdminUserDetailPresentationTest extends WebTestCase
{
    public function testAnonymousAndTeacherCannotOpenAUserDetail(): void
    {
        $target = $this->targetEmail('closed');
        $this->persistUser($target, UserRole::Teacher, 'Kapali', 'Kayit', [UserRole::Parent]);
        $id = $this->userId($target);

        $anonymous = $this->browser();
        $anonymous->request('GET', '/yonetim/kullanicilar/'.$id);
        self::assertResponseRedirects('/giris');

        $teacher = $this->browser();
        $this->login($teacher, 'user_detail_view_teacher@example.com', UserRole::Teacher, 'Deneme', 'Ogretmen');
        $teacher->request('GET', '/yonetim/kullanicilar/'.$id);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDetailShowsTurkishLabelsAndSeparateForms(): void
    {
        $email = $this->targetEmail('long');
        $first = str_repeat('Uzunad', 8);
        $last = str_repeat('Soyad', 8);
        $this->persistUser($email, UserRole::Teacher, $first, $last, [UserRole::Parent, UserRole::HeadTeacher]);
        $id = $this->userId($email);
        $client = $this->browser();
        $this->login($client, 'user_detail_view_admin@example.com', UserRole::Admin, 'Yonetici', 'Hesap');

        $crawler = $client->request('GET', '/yonetim/kullanicilar/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Kullanıcı detayı');
        self::assertSelectorTextContains('body', 'Hesap bilgilerini, rollerini ve durumunu yönet.');
        self::assertSelectorTextContains('.admin-user-detail-name', $first);
        self::assertSelectorTextContains('.admin-user-detail-email', $email);
        self::assertSelectorTextContains('.admin-user-avatar', 'US');
        self::assertSelectorTextContains('.admin-user-detail-pills', 'Aktif');
        self::assertSelectorTextContains('.admin-user-detail-pills', 'Öğretmen');
        self::assertSelectorTextContains('.admin-user-detail-pills', 'Veli');
        self::assertSelectorTextContains('.admin-user-detail-pills', 'Zümre başkanı');
        self::assertSelectorTextContains('.admin-user-detail-pills', 'Kullanıcı');
        self::assertSelectorTextContains('.admin-user-detail-meta', 'Doğrulanmış');
        self::assertMatchesRegularExpression(
            '/\d{1,2} (Oca|Şub|Mar|Nis|May|Haz|Tem|Ağu|Eyl|Eki|Kas|Ara) \d{4} · \d{2}:\d{2}/',
            $crawler->filter('.admin-user-detail-date')->text(),
        );
        self::assertSelectorExists('a[href="/yonetim/kullanicilar"]');
        self::assertStringNotContainsString('ROLE_', $crawler->filter('.admin-user-detail-page')->text());
        self::assertStringNotContainsString('ayse@example.com', $crawler->filter('.admin-user-detail-page')->text());
        self::assertStringNotContainsString('Tasarım örneği', $crawler->filter('.admin-user-detail-page')->text());

        $roles = $crawler->filter('form[name="admin_user_roles"]');
        $status = $crawler->filter('form[name="admin_user_status"]');
        self::assertSame(1, $roles->count());
        self::assertSame(1, $status->count());
        self::assertStringContainsString('/roller', (string) $roles->attr('action'));
        self::assertStringContainsString('/durum', (string) $status->attr('action'));
        self::assertNotSame($roles->attr('action'), $status->attr('action'));
        self::assertStringNotContainsString('admin_user_status', $roles->html());
        self::assertStringNotContainsString('name="admin_user_roles', $status->html());
        self::assertSelectorExists('form[name="admin_user_roles"] input[name="admin_user_roles[_token]"]');
        self::assertSelectorExists('form[name="admin_user_status"] input[name="admin_user_status[_token]"]');
        self::assertSelectorExists('form[name="admin_user_roles"] input[value="'.UserRole::Teacher->value.'"][checked]');
        self::assertSelectorExists('form[name="admin_user_roles"] input[value="'.UserRole::Parent->value.'"][checked]');
        self::assertSelectorExists('form[name="admin_user_roles"] input[value="'.UserRole::HeadTeacher->value.'"][checked]');
        self::assertSelectorExists('form[name="admin_user_roles"] input[value="'.UserRole::Student->value.'"]:not([checked])');
        self::assertSelectorNotExists('input[value="ROLE_USER"]');
        self::assertSelectorTextContains('form[name="admin_user_roles"]', 'Öğrenci');
        self::assertSelectorTextContains('form[name="admin_user_roles"]', 'Zümre başkanı');
        self::assertSelectorTextContains('form[name="admin_user_roles"]', 'Rol değişikliğini onaylıyorum');
        self::assertSelectorTextContains('form[name="admin_user_status"]', 'Askıda');
        self::assertSelectorTextContains('form[name="admin_user_status"]', 'Arşivlenmiş');
        self::assertSelectorTextContains('form[name="admin_user_status"]', 'İşlem seçin');
        self::assertSelectorTextContains('form[name="admin_user_status"]', 'Durum değişikliğini onaylıyorum');
        self::assertSame('suspend', $crawler->filter('form[name="admin_user_status"] option:contains("Askıda")')->attr('value'));
    }

    public function testInvalidRoleSubmitKeepsSelectionAndDoesNotChangeRoles(): void
    {
        $email = $this->targetEmail('roles');
        $this->persistUser($email, UserRole::Teacher, 'Rol', 'Aday', [UserRole::Parent]);
        $id = $this->userId($email);
        $before = $this->accountState($email);
        $client = $this->browser();
        $this->login($client, 'user_detail_view_roles_admin@example.com', UserRole::Admin, 'Rol', 'Yonetici');
        $crawler = $client->request('GET', '/yonetim/kullanicilar/'.$id);
        $token = $crawler->filter('input[name="admin_user_roles[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/yonetim/kullanicilar/'.$id.'/roller', [
            'admin_user_roles' => [
                'roles' => [UserRole::Teacher->value, UserRole::Parent->value, UserRole::Student->value],
                'reasonCode' => '',
                '_token' => $token,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="alert"]');
        self::assertSelectorTextContains('[role="alert"]', 'Onay gereklidir.');
        self::assertSelectorExists('form[name="admin_user_roles"] input[value="'.UserRole::Teacher->value.'"][checked]');
        self::assertSelectorExists('form[name="admin_user_roles"] input[value="'.UserRole::Parent->value.'"][checked]');
        self::assertSelectorExists('form[name="admin_user_roles"] input[value="'.UserRole::Student->value.'"][checked]');
        self::assertSelectorExists('form[name="admin_user_status"]');
        self::assertSame($before, $this->accountState($email));
    }

    public function testInvalidStatusSubmitKeepsSelectionAndDoesNotChangeStatus(): void
    {
        $email = $this->targetEmail('status');
        $this->persistUser($email, UserRole::Teacher, 'Durum', 'Aday');
        $id = $this->userId($email);
        $before = $this->accountState($email);
        $client = $this->browser();
        $this->login($client, 'user_detail_view_status_admin@example.com', UserRole::Admin, 'Durum', 'Yonetici');
        $crawler = $client->request('GET', '/yonetim/kullanicilar/'.$id);
        $token = $crawler->filter('input[name="admin_user_status[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/yonetim/kullanicilar/'.$id.'/durum', [
            'admin_user_status' => [
                'action' => 'suspend',
                'reasonCode' => '',
                '_token' => $token,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="admin_user_status"] [role="alert"]', 'Onay gereklidir.');
        self::assertSelectorExists('form[name="admin_user_status"] option[value="suspend"][selected]');
        self::assertSelectorExists('form[name="admin_user_roles"]');
        self::assertSame($before, $this->accountState($email));
    }

    public function testRolePostWithoutCsrfDoesNotChangeRoles(): void
    {
        $email = $this->targetEmail('csrf');
        $this->persistUser($email, UserRole::Teacher, 'Csrf', 'Aday');
        $id = $this->userId($email);
        $before = $this->accountState($email);
        $client = $this->browser();
        $this->login($client, 'user_detail_view_csrf_admin@example.com', UserRole::Admin, 'Csrf', 'Yonetici');

        $client->request('POST', '/yonetim/kullanicilar/'.$id.'/roller', [
            'admin_user_roles' => [
                'roles' => [UserRole::Student->value],
                'reasonCode' => 'privilege_adjustment',
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->accountState($email));
    }

    public function testHiddenFormsKeepTheReadOnlySummary(): void
    {
        $protected = $this->targetEmail('protected');
        $this->persistUser($protected, UserRole::Teacher, 'Korunan', 'Yonetici', [UserRole::SuperAdmin]);
        $protectedId = $this->userId($protected);
        $client = $this->browser();
        $this->login($client, 'user_detail_view_self_admin@example.com', UserRole::Admin, 'Kendi', 'Hesap');
        $selfId = $this->userId('user_detail_view_self_admin@example.com');

        $client->request('GET', '/yonetim/kullanicilar/'.$selfId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-user-detail-name', 'Kendi Hesap');
        self::assertSelectorTextContains('body', 'Kendi hesabınız üzerinde rol/durum değişikliği yapılamaz.');
        self::assertSelectorNotExists('form[name="admin_user_roles"]');
        self::assertSelectorNotExists('form[name="admin_user_status"]');

        $client->request('GET', '/yonetim/kullanicilar/'.$protectedId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-user-detail-name', 'Korunan Yonetici');
        self::assertSelectorTextContains('.admin-user-detail-pills', 'Süper Yönetici');
        self::assertSelectorTextContains('body', 'Süper yönetici hesabı uygulama üzerinden yönetilemez.');
        self::assertSelectorNotExists('form[name="admin_user_roles"]');
        self::assertSelectorNotExists('form[name="admin_user_status"]');
    }

    private function targetEmail(string $suffix): string
    {
        return 'user_detail_view_'.$suffix.'@example.com';
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
        array $extraRoles = [],
    ): void {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', $firstName, $lastName, $role);
        $user->markEmailVerified(new \DateTimeImmutable('2026-02-02 00:00:00'));
        $user->transitionTo(UserStatus::Active);
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
        $this->persistUser($email, UserRole::Teacher, $firstName, $lastName, UserRole::Teacher === $role ? [] : [$role]);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    private function userId(string $email): string
    {
        $state = $this->accountState($email);

        return $state['id'];
    }

    /**
     * @return array{id: string, roles: list<string>, status: string}
     */
    private function accountState(string $email): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByNormalizedEmail(strtolower($email));
        self::assertNotNull($user);
        $roles = array_values($user->getRoles());
        sort($roles);
        $state = [
            'id' => $user->getId()->toRfc4122(),
            'roles' => $roles,
            'status' => $user->getStatus()->value,
        ];
        $this->closeDatabase();
        self::ensureKernelShutdown();

        return $state;
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
                    "DELETE FROM users WHERE normalized_email LIKE 'user_detail_view_%@example.com'",
                );
            }
            $this->closeDatabase();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
