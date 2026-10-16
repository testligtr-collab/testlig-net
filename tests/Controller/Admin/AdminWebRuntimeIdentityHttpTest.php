<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\PosixWebRuntimeIdentityProbe;
use App\Security\WebRuntimeIdentityReader;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminWebRuntimeIdentityHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';
    private const NONCE = 'abcdef0123456789';

    protected function tearDown(): void
    {
        $this->disableProbe();
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'web_runtime_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function testAnonymousStudentAdminAndClosedFlagRevealNoIdentity(): void
    {
        $this->enableProbe();
        $anonymous = static::createClient();
        $anonymousSpy = $this->installSpy($anonymous);
        $anonymous->request('POST', '/yonetim/calisma-kimligi');
        self::assertResponseRedirects('/giris');
        self::assertSame(0, $anonymousSpy->calls);
        self::assertStringNotContainsString('"euid"', (string) $anonymous->getResponse()->getContent());

        $student = static::createClient();
        $this->loginAs($student, 'web_runtime_student@example.com', UserRole::Student);
        $studentSpy = $this->installSpy($student);
        $student->request('POST', '/yonetim/calisma-kimligi', $this->payload($student, self::NONCE));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $studentSpy->calls);
        self::assertStringNotContainsString('"euid"', (string) $student->getResponse()->getContent());

        $admin = static::createClient();
        $this->loginAs($admin, 'web_runtime_admin@example.com', UserRole::Admin);
        $adminSpy = $this->installSpy($admin);
        $admin->request('POST', '/yonetim/calisma-kimligi', $this->payload($admin, self::NONCE));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $adminSpy->calls);
        self::assertStringNotContainsString('"euid"', (string) $admin->getResponse()->getContent());

        $this->disableProbe();
        $closed = static::createClient();
        $this->loginAs($closed, 'web_runtime_closed@example.com', UserRole::SuperAdmin);
        $closedSpy = $this->installSpy($closed);
        $closed->request('POST', '/yonetim/calisma-kimligi', $this->payload($closed, self::NONCE));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $closedSpy->calls);
        self::assertStringNotContainsString('"euid"', (string) $closed->getResponse()->getContent());
    }

    public function testStaleSuperAdminAndInvalidRequestDoNotReadIdentity(): void
    {
        $this->enableProbe();
        $unverified = static::createClient();
        $user = $this->loginAs($unverified, 'web_runtime_unverified@example.com', UserRole::SuperAdmin);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$user->getId()->toBinary()],
        );
        $unverifiedSpy = $this->installSpy($unverified);
        $unverified->request('POST', '/yonetim/calisma-kimligi', $this->payload($unverified, self::NONCE));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $unverifiedSpy->calls);
        self::assertStringNotContainsString('"euid"', (string) $unverified->getResponse()->getContent());

        $suspended = static::createClient();
        $suspendedUser = $this->loginAs($suspended, 'web_runtime_suspended@example.com', UserRole::SuperAdmin);
        $suspendedEm = $suspended->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $suspendedEm);
        $suspendedEm->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $suspendedUser->getId()->toBinary()],
        );
        $suspendedSpy = $this->installSpy($suspended);
        $suspended->request('POST', '/yonetim/calisma-kimligi', $this->payload($suspended, self::NONCE));
        self::assertNotSame(200, $suspended->getResponse()->getStatusCode());
        self::assertSame(0, $suspendedSpy->calls);
        self::assertStringNotContainsString('"euid"', (string) $suspended->getResponse()->getContent());

        $client = static::createClient();
        $this->loginAs($client, 'web_runtime_sa@example.com', UserRole::SuperAdmin);
        $spy = $this->installSpy($client);
        $client->request('GET', '/yonetim/calisma-kimligi');
        self::assertResponseStatusCodeSame(405);
        self::assertSame(0, $spy->calls);
        self::assertStringNotContainsString('"euid"', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/calisma-kimligi', ['nonce' => self::NONCE]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $spy->calls);
        self::assertStringNotContainsString('"euid"', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/calisma-kimligi?nonce=querynoncevalue1', [
            '_token' => $this->csrf($client),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $spy->calls);
        self::assertStringNotContainsString('querynoncevalue1', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('"euid"', (string) $client->getResponse()->getContent());

        $client->request('POST', '/yonetim/calisma-kimligi', $this->payload($client, 'short'));
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $spy->calls);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testEnabledSuperAdminReceivesUnresolvedCliEvidenceWithoutNumbers(): void
    {
        $this->enableProbe();
        $client = static::createClient();
        $this->loginAs($client, 'web_runtime_measured@example.com', UserRole::SuperAdmin);
        $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/yonetim/calisma-kimligi', (string) $client->getResponse()->getContent());

        $spy = $this->installSpy($client);
        $client->request('POST', '/yonetim/calisma-kimligi', $this->payload($client, self::NONCE));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $spy->calls);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Pragma', 'no-cache');
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame([
            'nonce' => self::NONCE,
            'status' => 'unresolved',
        ], $payload);
    }

    private function installSpy(KernelBrowser $client): CountingWebRuntimeIdentityReader
    {
        $spy = new CountingWebRuntimeIdentityReader(new PosixWebRuntimeIdentityProbe());
        $client->getContainer()->set(WebRuntimeIdentityReader::class, $spy);

        return $spy;
    }

    /**
     * @return array{_token: string, nonce: string}
     */
    private function payload(KernelBrowser $client, string $nonce): array
    {
        return [
            '_token' => $this->csrf($client),
            'nonce' => $nonce,
        ];
    }

    private function csrf(KernelBrowser $client): string
    {
        $session = $client->getRequest()->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($client->getRequest());
        try {
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);
            $value = $tokens->getToken('web_runtime_identity')->getValue();
            $session->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }

    private function enableProbe(): void
    {
        $_SERVER['WEB_RUNTIME_IDENTITY_ENABLED'] = '1';
        $_ENV['WEB_RUNTIME_IDENTITY_ENABLED'] = '1';
        putenv('WEB_RUNTIME_IDENTITY_ENABLED=1');
    }

    private function disableProbe(): void
    {
        unset($_SERVER['WEB_RUNTIME_IDENTITY_ENABLED'], $_ENV['WEB_RUNTIME_IDENTITY_ENABLED']);
        putenv('WEB_RUNTIME_IDENTITY_ENABLED');
    }

    private function loginAs(KernelBrowser $client, string $email, UserRole $role): User
    {
        $user = $this->createUser($email, $role);
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();

        return $user;
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
}

class CountingWebRuntimeIdentityReader extends WebRuntimeIdentityReader
{
    public int $calls = 0;

    public function read(string $nonce): array
    {
        ++$this->calls;

        return parent::read($nonce);
    }
}
