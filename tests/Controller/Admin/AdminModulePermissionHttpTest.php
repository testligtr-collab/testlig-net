<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\ModeratorModuleGrant;
use App\Enum\QuestionScope;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\QuestionException;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use App\Security\UserModulePermissionPolicy;
use App\Service\QuestionManager;
use App\Service\UserFactory;
use App\Service\UserModulePermissionManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminModulePermissionHttpTest extends WebTestCase
{
    public function testAnonymousStudentTeacherModeratorAndAdminCannotManagePermissions(): void
    {
        $anon = $this->browser();
        $anon->request('GET', '/yonetim/rol-yetkileri');
        self::assertResponseRedirects('/giris');

        foreach ([
            [UserRole::Student, 'module_perm_student@example.com'],
            [UserRole::Teacher, 'module_perm_teacher@example.com'],
            [UserRole::Moderator, 'module_perm_moderator@example.com'],
            [UserRole::Admin, 'module_perm_admin@example.com'],
        ] as [$role, $email]) {
            self::assertInstanceOf(UserRole::class, $role);
            self::assertIsString($email);
            $client = $this->browser();
            $this->login($client, $email, $role);
            $client->request('GET', '/yonetim/rol-yetkileri');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/yonetim');
            self::assertSelectorNotExists('a[href="/yonetim/rol-yetkileri"]');
        }
    }

    public function testGrantRevokeConflictAuditAndNextRequest(): void
    {
        $super = $this->browser();
        $this->login($super, 'module_perm_sa@example.com', UserRole::SuperAdmin);
        $moderator = $this->persistRole('module_perm_target@example.com', UserRole::Moderator, 'Hedef', 'Moderator');
        $this->persistRole('module_perm_expert@example.com', UserRole::ExpertTeacher, 'Uzman', 'Ogretmen');

        $expertClient = $this->browser();
        $this->login($expertClient, 'module_perm_expert@example.com', UserRole::ExpertTeacher);
        $expertClient->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseIsSuccessful();

        $moderatorClient = $this->browser();
        $this->login($moderatorClient, 'module_perm_target@example.com', UserRole::Moderator);
        $moderatorClient->request('GET', '/yonetim/sorular');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/yonetim/sorular/yeni', (string) $moderatorClient->getResponse()->getContent());
        $moderatorClient->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseStatusCodeSame(403);
        $moderatorClient->request('POST', '/yonetim/sorular/yeni', ['subject_id' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $moderatorClient->request('GET', '/yonetim/icerikler/yeni');
        self::assertResponseStatusCodeSame(403);
        $moderatorClient->request('GET', '/yonetim/testler/yeni');
        self::assertResponseStatusCodeSame(403);

        $super->request('GET', '/yonetim/rol-yetkileri');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rol ve modül izinleri');
        self::assertSelectorExists('a[href="/yonetim/rol-yetkileri"]');
        self::assertSelectorTextContains('body', 'Hedef Moderator');
        $super->request('GET', '/yonetim/rol-yetkileri?q=module-perm-yok-zzzz');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Filtrelere uygun hesap bulunamadı.');

        $detail = '/yonetim/rol-yetkileri/'.$moderator->getId()->toRfc4122();
        $super->request('GET', $detail);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="question.manage"]');
        self::assertSelectorNotExists('input[value="ROLE_ADMIN"]');
        self::assertSelectorTextContains('body', 'Rolden gelen erişim');
        self::assertSelectorTextContains('body', 'Yayın ve arşiv bu ekrandan verilmez.');
        $token = (string) $super->getCrawler()->filter('input[name="_token"]')->attr('value');
        $version = (string) $super->getCrawler()->filter('input[name="version"]')->attr('value');
        self::assertSame('0', $version);

        $super->request('POST', $detail, ['version' => $version, 'permissions' => ['question.manage']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->grantCount($moderator));

        $super->request('POST', $detail, [
            '_token' => $token,
            'version' => $version,
            'permissions' => ['not_a_real_permission'],
        ]);
        self::assertResponseRedirects($detail);
        $super->followRedirect();
        self::assertSelectorTextContains('body', 'Seçilen izin kullanılamaz.');
        self::assertSame(0, $this->grantCount($moderator));
        self::assertSame(0, $this->auditCount($moderator));

        $super->request('POST', $detail, [
            '_token' => $token,
            'version' => $version,
            'permissions' => [ModeratorModuleGrant::QuestionManage->value],
        ]);
        self::assertResponseRedirects($detail);
        $super->followRedirect();
        self::assertSelectorTextContains('body', 'Modül izinleri güncellendi.');
        self::assertSame([ModeratorModuleGrant::QuestionManage->value], $this->grantCodes($moderator));
        self::assertSame(1, $this->auditCount($moderator));

        $moderatorClient->request('GET', '/yonetim/sorular');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/yonetim/sorular/yeni', (string) $moderatorClient->getResponse()->getContent());
        $moderatorClient->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseIsSuccessful();
        $editorToken = (string) $moderatorClient->getCrawler()->filter('input[name="_token"]')->attr('value');
        $moderatorClient->request('POST', '/yonetim/sorular/yeni', ['_token' => $editorToken]);
        self::assertNotSame(403, $moderatorClient->getResponse()->getStatusCode());
        $moderatorClient->request('GET', '/yonetim/icerikler/yeni');
        self::assertResponseStatusCodeSame(403);
        $moderatorClient->request('GET', '/yonetim/testler/yeni');
        self::assertResponseStatusCodeSame(403);
        $moderatorClient->request('GET', '/yonetim/kurumlar');
        self::assertResponseStatusCodeSame(403);
        $moderatorClient->request('GET', '/yonetim/odemeler');
        self::assertResponseStatusCodeSame(403);
        $moderatorClient->request('GET', '/yonetim/denetim');
        self::assertResponseStatusCodeSame(403);

        $super->request('GET', $detail);
        $freshToken = (string) $super->getCrawler()->filter('input[name="_token"]')->attr('value');
        $super->request('POST', $detail, [
            '_token' => $freshToken,
            'version' => '0',
            'permissions' => [ModeratorModuleGrant::LearningContentManage->value],
        ]);
        self::assertResponseRedirects($detail);
        $super->followRedirect();
        self::assertSelectorTextContains('body', 'Bu hesap başka bir işlemde güncellendi.');
        self::assertSame([ModeratorModuleGrant::QuestionManage->value], $this->grantCodes($moderator));
        self::assertSame(1, $this->auditCount($moderator));

        $super->request('GET', $detail);
        $removeToken = (string) $super->getCrawler()->filter('input[name="_token"]')->attr('value');
        $super->request('POST', $detail, [
            '_token' => $removeToken,
            'version' => '1',
        ]);
        self::assertResponseRedirects($detail);
        $super->followRedirect();
        self::assertSelectorTextContains('body', 'Modül izinleri güncellendi.');
        self::assertSame([], $this->grantCodes($moderator));

        $moderatorClient->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseStatusCodeSame(403);
        $expertClient->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseIsSuccessful();

        $super->disableReboot();
        $superUser = $this->findUser('module_perm_sa@example.com');
        self::assertInstanceOf(User::class, $superUser);
        $selfToken = $this->csrf($super, 'module_permissions_'.$superUser->getId()->toRfc4122());
        $super->request('POST', '/yonetim/rol-yetkileri/'.$superUser->getId()->toRfc4122(), [
            '_token' => $selfToken,
            'version' => '0',
            'permissions' => [ModeratorModuleGrant::QuestionManage->value],
        ]);
        $super->enableReboot();
        self::assertResponseRedirects('/yonetim/rol-yetkileri');
        $super->followRedirect();
        self::assertSelectorTextContains('body', 'Bu hesabın modül izni yönetilemez.');
        self::assertSame(0, $this->grantCount($superUser));
        self::assertNotContains(UserRole::Admin->value, $superUser->getRoles());
    }

    public function testSuspendedActorAndSuspendedTargetCannotUseAGrant(): void
    {
        $super = $this->browser();
        $this->login($super, 'module_perm_sa2@example.com', UserRole::SuperAdmin);
        $moderator = $this->persistRole('module_perm_target2@example.com', UserRole::Moderator, 'Pasif', 'Moderator');
        $moderatorClient = $this->browser();
        $this->login($moderatorClient, 'module_perm_target2@example.com', UserRole::Moderator);
        $detail = '/yonetim/rol-yetkileri/'.$moderator->getId()->toRfc4122();
        $super->request('GET', $detail);
        $token = (string) $super->getCrawler()->filter('input[name="_token"]')->attr('value');
        $super->request('POST', $detail, [
            '_token' => $token,
            'version' => '0',
            'permissions' => [ModeratorModuleGrant::QuestionManage->value],
        ]);
        self::assertResponseRedirects($detail);

        $this->setStatus('module_perm_target2@example.com', UserStatus::Suspended);
        $moderatorClient->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([ModeratorModuleGrant::QuestionManage->value], $this->grantCodes($moderator));

        $this->setStatus('module_perm_target2@example.com', UserStatus::Active);
        $moderatorClient->request('GET', '/yonetim/sorular/yeni');
        self::assertResponseIsSuccessful();

        $super->request('GET', $detail);
        $staleToken = (string) $super->getCrawler()->filter('input[name="_token"]')->attr('value');
        $this->setStatus('module_perm_sa2@example.com', UserStatus::Suspended);
        $super->request('POST', $detail, [
            '_token' => $staleToken,
            'version' => '1',
            'permissions' => [],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([ModeratorModuleGrant::QuestionManage->value], $this->grantCodes($moderator));
    }

    public function testDirectGrantDoesNotReplaceInstitutionMembership(): void
    {
        self::bootKernel();
        $moderator = $this->persistRole('module_perm_scope@example.com', UserRole::Moderator, 'Kapsam', 'Moderator');
        $super = $this->findUser('module_perm_sa3@example.com');
        if (!$super instanceof User) {
            $super = $this->persistRole('module_perm_sa3@example.com', UserRole::SuperAdmin, 'Kapsam', 'Super');
        }
        $manager = static::getContainer()->get(UserModulePermissionManager::class);
        self::assertInstanceOf(UserModulePermissionManager::class, $manager);
        $manager->replace($super->getId(), $moderator->getId(), 0, [
            ModeratorModuleGrant::QuestionManage->value,
            ModeratorModuleGrant::LearningContentManage->value,
            ModeratorModuleGrant::AssessmentAuthor->value,
        ]);
        $questions = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $questions);
        $create = new \ReflectionMethod(QuestionManager::class, 'assertActorMayCreate');
        $platformDenied = false;
        try {
            $create->invoke($questions, $moderator, QuestionScope::Platform, null);
        } catch (QuestionException) {
            $platformDenied = true;
        }
        self::assertFalse($platformDenied);
        $institutionDenied = false;
        try {
            $create->invoke($questions, $moderator, QuestionScope::Institution, null);
        } catch (QuestionException) {
            $institutionDenied = true;
        }
        self::assertTrue($institutionDenied);
        $policy = static::getContainer()->get(UserModulePermissionPolicy::class);
        self::assertInstanceOf(UserModulePermissionPolicy::class, $policy);
        self::assertTrue($policy->allows($moderator, ModeratorModuleGrant::QuestionManage));
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $fresh = $users->findOneById($moderator->getId());
        self::assertInstanceOf(User::class, $fresh);
        $fresh->transitionTo(UserStatus::Suspended);
        $users->save($fresh);
        $cache = static::getContainer()->get(InstitutionAuthorizationCacheInvalidator::class);
        self::assertInstanceOf(InstitutionAuthorizationCacheInvalidator::class, $cache);
        $cache->invalidateUser($fresh->getId());
        self::assertFalse($policy->allows($fresh, ModeratorModuleGrant::QuestionManage));
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function login(KernelBrowser $client, string $email, UserRole $role): void
    {
        $this->persistRole($email, $role, 'Ad', 'Min');
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
        $client->followRedirect();
    }

    private function persistRole(string $email, UserRole $role, string $first, string $last): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $existing = $this->findUser($email);
        if ($existing instanceof User) {
            $this->closeDatabase();

            return $existing;
        }
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', $first, $last, $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);
        $this->closeDatabase();

        return $user;
    }

    private function findUser(string $email): ?User
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);

        return $users->findOneByNormalizedEmail(mb_strtolower($email));
    }

    private function setStatus(string $email, UserStatus $status): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user);
        if ($user->getStatus() !== $status) {
            $user->transitionTo($status);
            /** @var UserRepository $users */
            $users = static::getContainer()->get(UserRepository::class);
            $users->save($user);
        }
        $cache = static::getContainer()->get(InstitutionAuthorizationCacheInvalidator::class);
        self::assertInstanceOf(InstitutionAuthorizationCacheInvalidator::class, $cache);
        $cache->invalidateUser($user->getId());
        $this->closeDatabase();
        self::ensureKernelShutdown();
    }

    private function csrf(KernelBrowser $client, string $id): string
    {
        $manager = $client->getContainer()->get(CsrfTokenManagerInterface::class);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);

        return $manager->getToken($id)->getValue();
    }

    private function grantCount(User $user): int
    {
        return \count($this->grantCodes($user));
    }

    /**
     * @return list<string>
     */
    private function grantCodes(User $user): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $rows = $em->getConnection()->fetchFirstColumn(
            'SELECT permission_code FROM user_module_permissions WHERE user_id = :id ORDER BY permission_code ASC',
            ['id' => $user->getId()->toBinary()],
            ['id' => ParameterType::BINARY],
        );
        $this->closeDatabase();
        $codes = [];
        foreach ($rows as $code) {
            if (\is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function auditCount(User $user): int
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $count = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = :action AND subject_user_id = :id',
            ['action' => SecurityAuditAction::ModulePermissionChanged->value, 'id' => $user->getId()->toBinary()],
            ['id' => ParameterType::BINARY],
        );
        $this->closeDatabase();

        return $count;
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
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement(
                    "DELETE p FROM user_module_permissions p INNER JOIN users u ON p.user_id = u.id WHERE u.normalized_email LIKE 'module_perm_%@example.com'",
                );
                $em->getConnection()->executeStatement(
                    "DELETE s FROM user_module_permission_states s INNER JOIN users u ON s.user_id = u.id WHERE u.normalized_email LIKE 'module_perm_%@example.com'",
                );
                $em->getConnection()->executeStatement(
                    "DELETE e FROM security_audit_events e INNER JOIN users u ON e.subject_user_id = u.id OR e.actor_user_id = u.id WHERE u.normalized_email LIKE 'module_perm_%@example.com'",
                );
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'module_perm_%@example.com'",
                );
            }
            $this->closeDatabase();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
