<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\TeacherApplicationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminTeacherApplicationPresentationTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private string $pendingA = '';

    private string $pendingB = '';

    protected function setUp(): void
    {
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnonymousAndPlainAdminCannotOpenTheQueue(): void
    {
        $client = $this->browser();
        $client->request('GET', '/yonetim/ogretmen-basvurulari');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        self::assertResponseRedirects('/giris');

        $this->login($client, 'tapv-admin@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSummaryMatchesTheDatabaseAndFiltersKeepTheTotals(): void
    {
        $client = $this->browser();
        $this->login($client, 'tapv-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari');
        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertSame((string) $this->countStatus(null), trim($crawler->filter('[data-teacher-app-summary="total"]')->text()));
        self::assertSame((string) $this->countStatus(OnboardingApplicationStatus::Pending), trim($crawler->filter('[data-teacher-app-summary="pending"]')->text()));
        self::assertStringContainsString('Başvuru onayı öğretmen rolü vermez.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('TapvAda', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('İncele', (string) $client->getResponse()->getContent());

        $client->request('GET', '/yonetim/ogretmen-basvurulari?q=tapv-missing-zzzz');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Filtrelere uygun başvuru bulunamadı.', (string) $client->getResponse()->getContent());
        self::assertSame((string) $this->countStatus(null), trim($client->getCrawler()->filter('[data-teacher-app-summary="total"]')->text()));
        self::assertStringNotContainsString('TapvAda', (string) $client->getResponse()->getContent());
    }

    public function testStatusFilterAndPaginationUseTheRealRows(): void
    {
        $this->withKernel(function (): void {
            $manager = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $manager);
            $manager->markRejected($this->user('tapv-sa@example.com'), Uuid::fromString($this->pendingB), 'incomplete_documents');
        });

        $client = $this->browser();
        $this->login($client, 'tapv-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari?q=Tapvsoyad&status=rejected&page_size=1');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('TapvBora', $html);
        self::assertStringNotContainsString('TapvAda', $html);
        self::assertStringContainsString('Reddedildi', $html);
        self::assertCount(1, $client->getCrawler()->filter('table.admin-user-table tbody tr'));

        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingB);
        self::assertResponseIsSuccessful();
        $detail = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('incomplete_documents', $detail);
        self::assertStringContainsString('Listeye dön', $detail);
        self::assertStringContainsString('İşlem geçmişi', $detail);
        self::assertStringContainsString('Başvuru onayı öğretmen rolü vermez.', $detail);
        self::assertStringNotContainsString('>Onayla<', $detail);
    }

    public function testPageSizeLimitsTheVisibleRows(): void
    {
        $client = $this->browser();
        $this->login($client, 'tapv-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari?q=Tapvsoyad&page_size=1');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('table.admin-user-table tbody tr'));
        self::assertStringContainsString('Sonraki', (string) $client->getResponse()->getContent());
        self::assertSame('2 başvuru', trim($client->getCrawler()->filter('.admin-user-list__count')->text()));
    }

    public function testInvalidRejectReasonKeepsTheTypedCodeAndWritesNothing(): void
    {
        $before = $this->auditCount('teacher_application_rejected');
        $client = $this->browser();
        $this->login($client, 'tapv-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $token = $this->csrf($client, 'teacher_application_reject');
        $client->request('POST', '/yonetim/ogretmen-basvurulari/'.$this->pendingA.'/reddet', [
            '_token' => $token,
            'reason_code' => '1bad',
        ]);
        self::assertResponseRedirects('/yonetim/ogretmen-basvurulari/'.$this->pendingA);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('1bad', $client->getCrawler()->filter('#teacher-application-reason')->attr('value'));
        self::assertStringContainsString('Başvuru verisi geçersiz.', (string) $client->getResponse()->getContent());
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingA));
        self::assertSame('', $this->reasonCode($this->pendingA));
        self::assertSame($before, $this->auditCount('teacher_application_rejected'));
    }

    public function testEmptyQueueShowsTheEmptyCopy(): void
    {
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement('DELETE FROM teacher_applications');
        });

        $client = $this->browser();
        $this->login($client, 'tapv-sa@example.com');
        $client->request('GET', '/yonetim/ogretmen-basvurulari');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz öğretmen başvurusu yok.', (string) $client->getResponse()->getContent());
        self::assertSame('0', trim($client->getCrawler()->filter('[data-teacher-app-summary="total"]')->text()));
    }

    private function seed(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('tapv-sa@example.com', UserRole::SuperAdmin, 'Selin', 'Aydın');
            $this->createActive('tapv-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
            $this->createActive('tapv-a@example.com', UserRole::Student, 'TapvAda', 'Tapvsoyad');
            $this->createActive('tapv-b@example.com', UserRole::Student, 'TapvBora', 'Tapvsoyad');
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            $this->pendingA = $applications->submit($this->user('tapv-a@example.com'))->getId()->toRfc4122();
            $this->pendingB = $applications->submit($this->user('tapv-b@example.com'))->getId()->toRfc4122();
        });
    }

    private function createActive(string $email, UserRole $role, string $first, string $last): void
    {
        $factory = static::getContainer()->get(UserFactory::class);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $lifecycle->markEmailVerifiedAndActivate($user);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->flush();
        }
    }

    private function purge(): void
    {
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $ids = $em->getConnection()->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'tapv-%'");
            if ([] === $ids) {
                return;
            }
            $em->getConnection()->executeStatement('DELETE FROM teacher_applications WHERE user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM security_audit_events WHERE actor_user_id IN (?) OR subject_user_id IN (?)', [$ids, $ids], [ArrayParameterType::BINARY, ArrayParameterType::BINARY]);
            $em->getConnection()->executeStatement('DELETE FROM users WHERE email LIKE ?', ['tapv-%']);
        });
    }

    private function countStatus(?OnboardingApplicationStatus $status): int
    {
        if (null === $status) {
            return (int) $this->scalar('SELECT COUNT(*) FROM teacher_applications');
        }

        return (int) $this->scalar('SELECT COUNT(*) FROM teacher_applications WHERE status = ?', [$status->value]);
    }

    private function applicationStatus(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM teacher_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
    }

    private function reasonCode(string $id): string
    {
        $value = $this->scalar('SELECT decision_reason_code FROM teacher_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);

        return \is_string($value) ? $value : '';
    }

    private function auditCount(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', [$action]);
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params = []): int|string
    {
        $value = $this->withKernel(static function () use ($sql, $params): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchOne($sql, $params);
        });

        return \is_int($value) || \is_string($value) ? $value : '';
    }

    private function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function withKernel(callable $callback): mixed
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            return $callback();
        } finally {
            self::ensureKernelShutdown();
        }
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function csrf(KernelBrowser $client, string $intention): string
    {
        $session = $client->getRequest()->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($client->getRequest());
        try {
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);
            $value = $tokens->getToken($intention)->getValue();
            $session->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }
}
