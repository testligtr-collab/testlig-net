<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\ParentTestSummaryView;
use App\Dto\StudentProfileRequest;
use App\Entity\ParentStudentLink;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\ParentStudentLinkStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ParentStudentLinkRepository;
use App\Repository\UserRepository;
use App\Service\ParentLinkQuery;
use App\Service\ParentStudentLinkCodeManager;
use App\Service\StudentProfileManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\ParentStudentLinkDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class ParentPanelControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnonymousParentRouteRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/veli');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/veli/baglan');
        self::assertResponseRedirects('/giris');
    }

    public function testStaffCannotOpenParentPanel(): void
    {
        foreach ([
            UserRole::Teacher,
            UserRole::Moderator,
            UserRole::InstitutionManager,
            UserRole::Admin,
            UserRole::SuperAdmin,
        ] as $role) {
            $email = strtolower($role->name).'-panel@example.com';
            $this->createActive($email, $role);
            $client = static::createClient();
            $this->login($client, $email);
            $client->request('GET', '/veli');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/veli/baglan');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testParentSeesSummaryShellWithoutStudentSecrets(): void
    {
        $student = $this->createActive('secret-student@example.com', UserRole::Student, 'Ada', 'Yılmaz');
        $this->completeOnboarding($student, GradeLevel::Grade5, 'Gizli Okul', 'Gizli Şehir', 'Gizli Hedef');
        $this->createActive('secret-parent@example.com', UserRole::Parent, 'Murat', 'Demir');
        $this->link('secret-student@example.com', 'secret-parent@example.com');

        $client = static::createClient();
        $this->login($client, 'secret-parent@example.com');
        self::assertResponseRedirects('/veli');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('Merhaba, Murat', $html);
        self::assertStringContainsString('Ada Yılmaz', $html);
        self::assertStringContainsString('5. sınıf', $html);
        self::assertStringNotContainsString('secret-student@example.com', $html);
        self::assertStringNotContainsString('Gizli Okul', $html);
        self::assertStringNotContainsString('Gizli Şehir', $html);
        self::assertStringNotContainsString('Gizli Hedef', $html);
        self::assertStringNotContainsString('ciphertext', $html);
        self::assertStringNotContainsString('stable', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);
        self::assertSelectorExists('button.nav-toggle');
        self::assertSelectorExists('#parent-mobile-nav');
        self::assertSelectorExists('a[aria-current="page"]');
        foreach (['Panel', 'Çocuklarım', 'Test sonuçları', 'Hesabım'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertSelectorExists('form[action="/cikis"] input[name="_csrf_token"]');

        $client->request('GET', '/ogrenci/testler');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidCodeIsGenericAndCsrfIsRequired(): void
    {
        $this->createActive('csrf-parent@example.com', UserRole::Parent, 'Murat', 'Demir');
        $client = static::createClient();
        $this->login($client, 'csrf-parent@example.com');
        $client->request('POST', '/veli/baglan', ['code' => 'ABCD-EFGH-JKLM']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/veli/baglan');
        $client->submit($crawler->selectButton('Bağlantı kodunu gir')->form([
            'code' => 'LEAK-MARKER-XXXX',
        ]));
        self::assertResponseRedirects('/veli/baglan');
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kod geçersiz veya süresi dolmuş.', $html);
        self::assertStringNotContainsString('LEAK-MARKER-XXXX', $html);
    }

    public function testOtherChildAndRevokedLinkAreOpaque404(): void
    {
        $linked = $this->createActive('linked-student@example.com', UserRole::Student, 'Ada', 'Yılmaz');
        $this->completeOnboarding($linked, GradeLevel::Grade5, 'Gizli Okul');
        $other = $this->createActive('other-student@example.com', UserRole::Student, 'Bora', 'Kaya');
        $this->completeOnboarding($other, GradeLevel::Grade6, 'Başka Okul');
        $this->createActive('viewer-parent@example.com', UserRole::Parent, 'Murat', 'Demir');
        $this->createActive('other-parent@example.com', UserRole::Parent, 'Selin', 'Ak');
        $this->link('linked-student@example.com', 'viewer-parent@example.com');
        $this->link('other-student@example.com', 'other-parent@example.com');

        $otherRef = $this->referenceFor('other-parent@example.com', 'other-student@example.com');
        $client = static::createClient();
        $this->login($client, 'viewer-parent@example.com');
        $client->request('GET', '/veli/cocuk/'.$otherRef);
        self::assertResponseStatusCodeSame(404);

        $ownRef = $this->referenceFor('viewer-parent@example.com', 'linked-student@example.com');
        $this->endLink('linked-student@example.com', 'viewer-parent@example.com');
        $client = static::createClient();
        $this->login($client, 'viewer-parent@example.com');
        $client->request('GET', '/veli/cocuk/'.$ownRef);
        self::assertResponseStatusCodeSame(404);
    }

    public function testStudentCodeIsShownOnceAndOnlyOwnLinkCanBeRemoved(): void
    {
        $owner = $this->createActive('owner-student@example.com', UserRole::Student, 'Ada', 'Yılmaz');
        $this->completeOnboarding($owner, GradeLevel::Grade5, 'Gizli Okul');
        $this->createActive('owner-parent@example.com', UserRole::Parent, 'Murat', 'Demir');
        $intruder = $this->createActive('intruder-student@example.com', UserRole::Student, 'Cem', 'Usta');
        $this->completeOnboarding($intruder, GradeLevel::Grade4, 'Başka Okul');

        $client = static::createClient();
        $this->login($client, 'owner-student@example.com');
        $client->request('GET', '/ogrenci/profil/veli/kod');
        self::assertResponseStatusCodeSame(405);
        $crawler = $client->request('GET', '/ogrenci/profil');
        $client->submit($crawler->selectButton('Yeni bağlantı kodu oluştur')->form());
        self::assertResponseRedirects('/ogrenci/profil');
        $crawler = $client->followRedirect();
        $code = $crawler->filter('#parent-link-code')->attr('value');
        self::assertNotNull($code);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('15 dakika', $html);
        self::assertStringContainsString('bir kez', $html);
        self::assertStringNotContainsString('owner-parent@example.com', $html);
        $client->request('GET', '/ogrenci/profil');
        self::assertStringNotContainsString((string) $code, (string) $client->getResponse()->getContent());

        $this->link('owner-student@example.com', 'owner-parent@example.com');
        $reference = $this->referenceFor('owner-parent@example.com', 'owner-student@example.com');
        $client = static::createClient();
        $this->login($client, 'intruder-student@example.com');
        $crawler = $client->request('GET', '/ogrenci/profil');
        $token = $crawler->filter('#parent-link-revoke-token')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/ogrenci/profil/veli/kaldir', [
            '_token' => $token,
            'reference' => $reference,
        ]);
        self::assertResponseStatusCodeSame(404);
        self::ensureKernelShutdown();
        self::bootKernel();
        $links = static::getContainer()->get(ParentStudentLinkRepository::class);
        self::assertInstanceOf(ParentStudentLinkRepository::class, $links);
        $remaining = $links->findBy(['status' => ParentStudentLinkStatus::Verified]);
        self::assertNotEmpty($remaining);
    }

    public function testSummaryTemplateShowsPublishedCountsOnly(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render('parent/_summary.html.twig', [
            'summary' => new ParentTestSummaryView(
                'Toplama',
                'Matematik',
                'Tamamlandı',
                '01.01.2026 10:00 UTC',
                true,
                '8,00',
                '10,00',
                '80,0%',
                4,
                1,
                2,
            ),
        ]);
        self::assertStringContainsString('Toplama', $html);
        self::assertStringContainsString('8,00', $html);
        self::assertStringContainsString('80,0%', $html);
        self::assertStringContainsString('Doğru', $html);
        self::assertStringNotContainsString('çözüm', $html);
        self::assertStringNotContainsString('ciphertext', $html);
        self::assertStringNotContainsString('stable', $html);

        $progress = $twig->render('parent/_summary.html.twig', [
            'summary' => new ParentTestSummaryView(
                'Çıkarma',
                'Matematik',
                'Devam ediyor',
                '01.01.2026 11:00 UTC',
                false,
                null,
                null,
                null,
                null,
                null,
                null,
            ),
        ]);
        self::assertStringContainsString('Devam ediyor', $progress);
        self::assertStringNotContainsString('8,00', $progress);
    }

    private function link(string $studentEmail, string $parentEmail): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        $manager = static::getContainer()->get(ParentStudentLinkCodeManager::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(ParentStudentLinkCodeManager::class, $manager);
        $student = $users->findOneBy(['email' => $studentEmail]);
        $parent = $users->findOneBy(['email' => $parentEmail]);
        self::assertInstanceOf(User::class, $student);
        self::assertInstanceOf(User::class, $parent);
        $issued = $manager->issue($student);
        $manager->redeem($parent, $issued->displayCode, '203.0.113.80');
        self::ensureKernelShutdown();
    }

    private function endLink(string $studentEmail, string $parentEmail): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        $links = static::getContainer()->get(ParentStudentLinkRepository::class);
        $manager = static::getContainer()->get(ParentStudentLinkCodeManager::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(ParentStudentLinkRepository::class, $links);
        self::assertInstanceOf(ParentStudentLinkCodeManager::class, $manager);
        $student = $users->findOneBy(['email' => $studentEmail]);
        self::assertInstanceOf(User::class, $student);
        $match = null;
        foreach ($links->findVerifiedForStudent($student->getId()) as $link) {
            if ($link->getParent()->getEmail() === $parentEmail) {
                $match = $link;
            }
        }
        self::assertInstanceOf(ParentStudentLink::class, $match);
        $manager->revokeLink($student, $match->getId());
        self::ensureKernelShutdown();
    }

    private function referenceFor(string $parentEmail, string $studentEmail): string
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        $query = static::getContainer()->get(ParentLinkQuery::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(ParentLinkQuery::class, $query);
        $parent = $users->findOneBy(['email' => $parentEmail]);
        self::assertInstanceOf(User::class, $parent);
        foreach ($query->childrenForParent($parent) as $child) {
            if (str_contains($studentEmail, 'other-student') && str_contains($child->displayName, 'Bora')) {
                $reference = $child->reference;
                self::ensureKernelShutdown();

                return $reference;
            }
            if (str_contains($studentEmail, 'linked-student') && str_contains($child->displayName, 'Ada')) {
                $reference = $child->reference;
                self::ensureKernelShutdown();

                return $reference;
            }
            if (str_contains($studentEmail, 'owner-student') && str_contains($child->displayName, 'Ada')) {
                $reference = $child->reference;
                self::ensureKernelShutdown();

                return $reference;
            }
        }
        self::fail('Missing child reference.');
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => 'Guclu-Parola-123!',
        ]));
    }

    private function createActive(string $email, UserRole $role, string $first = 'Ayşe', string $last = 'Yılmaz'): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        self::assertInstanceOf(UserRepository::class, $users);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', $first, $last, $initial);
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $lifecycle->markEmailVerifiedAndActivate($user);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
            $users->save($user);
        }
        self::ensureKernelShutdown();

        return $user;
    }

    private function completeOnboarding(
        User $user,
        GradeLevel $grade,
        ?string $schoolName = null,
        ?string $city = null,
        ?string $learningGoal = null,
    ): void {
        self::ensureKernelShutdown();
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        $manager = static::getContainer()->get(StudentProfileManager::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(StudentProfileManager::class, $manager);
        $fresh = $users->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;
        $dto->schoolName = $schoolName;
        $dto->city = $city;
        $dto->learningGoal = $learningGoal;
        $manager->completeOnboarding($fresh, $dto);
        self::ensureKernelShutdown();
    }

    private function purge(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            ParentStudentLinkDbCleanup::deleteAll($connection);
            $schema = $connection->createSchemaManager();
            foreach (['student_profiles', 'security_audit_events', 'users'] as $table) {
                if ($schema->tablesExist([$table])) {
                    $connection->executeStatement('DELETE FROM '.$table);
                }
            }
            self::ensureKernelShutdown();
        } catch (\Throwable) {
        }
    }
}
