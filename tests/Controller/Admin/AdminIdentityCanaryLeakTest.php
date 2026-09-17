<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Canary leak scans for admin user identity HTML.
 */
final class AdminIdentityCanaryLeakTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';
    private const CANARY_EMAIL = 'canary_pwd_hash_SHOULD_NOT_LEAK@example.com';

    public function testUserDetailDoesNotLeakPasswordHashFromDatabase(): void
    {
        $client = static::createClient();
        $canary = $this->createCanaryUser();
        $hash = $this->fetchPasswordHash($canary->getId());
        $this->loginAs($client, 'admin_id_matrix_canary_sa@example.com', UserRole::SuperAdmin);

        self::assertStringStartsWith('$2y$', $hash);

        $client->request('GET', '/yonetim/kullanicilar/'.$canary->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($hash, $html);
        self::assertDoesNotMatchRegularExpression('/\$2y\$/', $html);
    }

    public function testUserListSearchDoesNotLeakPasswordHash(): void
    {
        $client = static::createClient();
        $canary = $this->createCanaryUser();
        $hash = $this->fetchPasswordHash($canary->getId());
        $this->loginAs($client, 'admin_id_matrix_canary_list_sa@example.com', UserRole::SuperAdmin);
        $searchTerm = 'canary_pwd_hash_SHOULD_NOT_LEAK';

        $client->request('GET', '/yonetim/kullanicilar', ['q' => $searchTerm]);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($hash, $html);
        self::assertDoesNotMatchRegularExpression('/\$2y\$/', $html);
        self::assertSelectorNotExists('.admin-flash');
    }

    private function createCanaryUser(): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $user = $factory->createAndPersist(
            self::CANARY_EMAIL,
            self::PASSWORD,
            'password_hash_canary',
            'Leak',
            UserRole::Teacher,
        );
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);

        return $user;
    }

    private function fetchPasswordHash(\Symfony\Component\Uid\Uuid $id): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hash = $em->getConnection()->fetchOne(
            'SELECT password FROM users WHERE id = ?',
            [$id->toBinary()],
        );
        self::assertIsString($hash);

        return $hash;
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): User
    {
        $user = $this->createPrivilegedUser($email, $role);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();

        return $user;
    }

    private function createPrivilegedUser(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Min', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $users->save($user);

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            if ($conn->createSchemaManager()->tablesExist(['users'])) {
                $conn->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'admin_id_matrix_%@example.com' OR normalized_email = ?",
                    [self::CANARY_EMAIL],
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
