<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
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
        $client = null;
        try {
            $client = self::getClient();
        } catch (\Throwable) {
            $client = null;
        }
        if ($client instanceof KernelBrowser) {
            $this->releaseClient($client);
        }
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement(
                "DELETE FROM users WHERE normalized_email LIKE 'web_runtime_%@example.com'",
            );
            if ($em->getConnection()->isConnected()) {
                $em->getConnection()->close();
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function testAnonymousStudentAdminAndClosedFlagRevealNoIdentity(): void
    {
        $this->enableProbe();
        $anonymous = static::createClient();
        WebRuntimeIdentityReader::resetReadCount();
        $anonymous->request('GET', '/yonetim/denetim');
        self::assertResponseRedirects('/giris');
        $this->assertReaderNotCalled($anonymous);
        WebRuntimeIdentityReader::resetReadCount();
        $anonymous->request('POST', '/yonetim/calisma-kimligi');
        self::assertResponseRedirects('/giris');
        $this->assertReaderNotCalled($anonymous);
        $this->releaseClient($anonymous);

        self::ensureKernelShutdown();
        $student = static::createClient();
        $this->loginAs($student, 'web_runtime_student@example.com', UserRole::Student);
        WebRuntimeIdentityReader::resetReadCount();
        $student->request('GET', '/yonetim/denetim');
        self::assertResponseStatusCodeSame(403);
        $this->assertReaderNotCalled($student);
        WebRuntimeIdentityReader::resetReadCount();
        $student->request('POST', '/yonetim/calisma-kimligi', $this->payload($student, self::NONCE));
        self::assertResponseStatusCodeSame(404);
        $this->assertReaderNotCalled($student);
        $this->releaseClient($student);

        self::ensureKernelShutdown();
        $admin = static::createClient();
        $this->loginAs($admin, 'web_runtime_admin@example.com', UserRole::Admin);
        WebRuntimeIdentityReader::resetReadCount();
        $admin->request('GET', '/yonetim/denetim');
        self::assertResponseStatusCodeSame(403);
        $this->assertReaderNotCalled($admin);
        WebRuntimeIdentityReader::resetReadCount();
        $admin->request('POST', '/yonetim/calisma-kimligi', $this->payload($admin, self::NONCE));
        self::assertResponseStatusCodeSame(404);
        $this->assertReaderNotCalled($admin);
        $this->releaseClient($admin);

        $this->disableProbe();
        self::ensureKernelShutdown();
        $closed = static::createClient();
        $this->loginAs($closed, 'web_runtime_closed@example.com', UserRole::SuperAdmin);
        WebRuntimeIdentityReader::resetReadCount();
        $closed->request('GET', '/yonetim/denetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Güvenlik denetim olayları');
        self::assertSelectorNotExists('form[action="/yonetim/calisma-kimligi"]');
        self::assertStringNotContainsString('/yonetim/calisma-kimligi', (string) $closed->getResponse()->getContent());
        self::assertStringNotContainsString('name="nonce"', (string) $closed->getResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $this->assertReaderNotCalled($closed);
        WebRuntimeIdentityReader::resetReadCount();
        $closed->request('POST', '/yonetim/calisma-kimligi', $this->payload($closed, self::NONCE));
        self::assertResponseStatusCodeSame(404);
        $this->assertReaderNotCalled($closed);
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
        WebRuntimeIdentityReader::resetReadCount();
        $unverified->request('POST', '/yonetim/calisma-kimligi', $this->payload($unverified, self::NONCE));
        self::assertResponseStatusCodeSame(404);
        $this->assertReaderNotCalled($unverified);
        $this->releaseClient($unverified);

        self::ensureKernelShutdown();
        $suspended = static::createClient();
        $suspendedUser = $this->loginAs($suspended, 'web_runtime_suspended@example.com', UserRole::SuperAdmin);
        $suspendedEm = $suspended->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $suspendedEm);
        $suspendedEm->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $suspendedUser->getId()->toBinary()],
        );
        WebRuntimeIdentityReader::resetReadCount();
        $suspended->request('POST', '/yonetim/calisma-kimligi', $this->payload($suspended, self::NONCE));
        self::assertNotSame(200, $suspended->getResponse()->getStatusCode());
        $this->assertReaderNotCalled($suspended);
        $this->releaseClient($suspended);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAs($client, 'web_runtime_sa@example.com', UserRole::SuperAdmin);
        WebRuntimeIdentityReader::resetReadCount();
        $client->request('GET', '/yonetim/calisma-kimligi');
        self::assertResponseStatusCodeSame(405);
        $this->assertReaderNotCalled($client);

        WebRuntimeIdentityReader::resetReadCount();
        $client->request('POST', '/yonetim/calisma-kimligi', ['nonce' => self::NONCE]);
        self::assertResponseStatusCodeSame(403);
        $this->assertReaderNotCalled($client);

        WebRuntimeIdentityReader::resetReadCount();
        $client->request('POST', '/yonetim/calisma-kimligi?nonce=querynoncevalue1', [
            '_token' => $this->csrf($client),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('', (string) $client->getResponse()->getContent());
        $this->assertReaderNotCalled($client);

        WebRuntimeIdentityReader::resetReadCount();
        $client->request('POST', '/yonetim/calisma-kimligi', $this->payload($client, 'short'));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('', (string) $client->getResponse()->getContent());
        $this->assertReaderNotCalled($client);
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

        WebRuntimeIdentityReader::resetReadCount();
        $crawler = $client->request('GET', '/yonetim/denetim');
        self::assertResponseIsSuccessful();
        self::assertSame(0, WebRuntimeIdentityReader::readCount());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorTextContains('h1', 'Güvenlik denetim olayları');
        $form = $crawler->filter('form[action="/yonetim/calisma-kimligi"]');
        self::assertCount(1, $form);
        $nonce = $form->filter('input[name="nonce"]')->attr('value');
        self::assertIsString($nonce);
        self::assertSame(1, preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));

        WebRuntimeIdentityReader::resetReadCount();
        $client->submit($form->form());
        self::assertResponseIsSuccessful();
        self::assertSame(1, WebRuntimeIdentityReader::readCount());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Pragma', 'no-cache');
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame([
            'nonce' => $nonce,
            'status' => 'unresolved',
        ], $payload);
    }

    private function releaseClient(KernelBrowser $client): void
    {
        try {
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            if ($em instanceof EntityManagerInterface && $em->getConnection()->isConnected()) {
                $em->getConnection()->close();
            }
        } catch (\Throwable) {
        }
        try {
            $client->getKernel()->shutdown();
        } catch (\Throwable) {
        }
    }

    private function assertReaderNotCalled(KernelBrowser $client): void
    {
        self::assertSame(0, WebRuntimeIdentityReader::readCount());
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        if (\is_array($decoded)) {
            self::assertArrayNotHasKey('euid', $decoded);
            self::assertArrayNotHasKey('egid', $decoded);
            self::assertArrayNotHasKey('groups', $decoded);
        }
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
