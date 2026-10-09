<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AcademicYear;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AcademicYearRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InstitutionAcademicYearWriteTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    protected function setUp(): void
    {
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testOwnerAndManagerCreateAndActivateAYearTheClassroomFormCanSelect(): void
    {
        $this->createActive('year-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('year-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('year-manager@example.com', UserRole::User, 'Mert', 'Kaya');
        $this->createActive('year-other@example.com', UserRole::User, 'Bora', 'Demir');
        $this->openInstitution('year-owner@example.com', 'Ada Koleji');
        $this->openInstitution('year-other@example.com', 'Bora Koleji');
        $this->addMember('year-owner@example.com', 'year-manager@example.com', InstitutionMembershipRole::Manager, 'Ada Koleji');
        $foreignId = $this->institutionId('Bora Koleji');

        $owner = $this->browser();
        $this->login($owner, 'year-owner@example.com');
        $owner->request('GET', '/kurum/akademik-yillar');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->yearCount());
        self::assertSame(0, $this->auditCount('academic_year_created'));
        $html = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Henüz eğitim dönemi yok.', $html);
        self::assertStringContainsString('no-store', (string) $owner->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('year-owner@example.com', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);

        $owner->request('GET', '/kurum/akademik-yillar');
        self::assertSame(0, $this->yearCount());

        $crawler = $owner->request('GET', '/kurum/siniflar/yeni');
        self::assertStringContainsString('Eğitim dönemlerini yönet', (string) $owner->getResponse()->getContent());
        self::assertStringNotContainsString('Dönem oluşturma bu ekranda yok.', (string) $owner->getResponse()->getContent());

        $crawler = $owner->request('GET', '/kurum/akademik-yillar');
        $form = $crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Pilot Donemi',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-15',
        ]);
        $owner->request($form->getMethod(), $form->getUri(), array_merge($form->getValues(), [
            'institution_id' => $foreignId,
        ]));
        self::assertResponseRedirects('/kurum/akademik-yillar');
        $owner->followRedirect();
        $html = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Pilot Donemi', $html);
        self::assertStringContainsString('Planlandı', $html);
        self::assertStringContainsString('01.09.2026', $html);
        self::assertStringContainsString('15.06.2027', $html);
        self::assertStringNotContainsString('Bora Koleji', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);
        self::assertSame('Ada Koleji', $this->yearInstitution('Pilot Donemi'));
        self::assertSame('planned', $this->yearStatus('Pilot Donemi'));
        $created = $this->latestAudit('academic_year_created');
        self::assertSame('academic_year_manager', $created['source'] ?? null);
        self::assertSame('panel_year_create', $created['reason_code'] ?? null);
        self::assertSame('planned', $created['new_status'] ?? null);
        self::assertArrayHasKey('institution_id', $created);
        self::assertArrayHasKey('academic_year_id', $created);
        self::assertStringNotContainsString('year-owner@example.com', (string) json_encode($created));
        $pilotReference = $this->activateReference($html);

        $crawler = $owner->request('GET', '/kurum/siniflar/yeni');
        self::assertStringContainsString('Pilot Donemi', (string) $owner->getResponse()->getContent());
        self::assertStringNotContainsString('Bora Donemi', (string) $owner->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('#classroom-year option')->count());

        $crawler = $owner->request('GET', '/kurum/akademik-yillar');
        $activate = $crawler->filter('form[action$="/aktiflestir"]')->form();
        $owner->request($activate->getMethod(), $activate->getUri(), $activate->getValues());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('onay kutusunu işaretleyin', (string) $owner->getResponse()->getContent());
        self::assertSame('planned', $this->yearStatus('Pilot Donemi'));
        self::assertSame(0, $this->auditCount('academic_year_activated'));

        $crawler = $owner->request('GET', '/kurum/akademik-yillar');
        $activate = $crawler->filter('form[action$="/aktiflestir"]')->form(['confirm' => '1']);
        $owner->submit($activate);
        self::assertResponseRedirects('/kurum/akademik-yillar');
        $owner->followRedirect();
        self::assertStringContainsString('Aktif', (string) $owner->getResponse()->getContent());
        self::assertStringNotContainsString('>Aktifleştir<', (string) $owner->getResponse()->getContent());
        self::assertSame('active', $this->yearStatus('Pilot Donemi'));
        $activated = $this->latestAudit('academic_year_activated');
        self::assertSame('academic_year_manager', $activated['source'] ?? null);
        self::assertSame('panel_year_activate', $activated['reason_code'] ?? null);
        self::assertSame('planned', $activated['old_status'] ?? null);
        self::assertSame('active', $activated['new_status'] ?? null);
        self::assertSame(1, $this->auditCount('academic_year_activated'));

        $crawler = $owner->request('GET', '/kurum/akademik-yillar');
        $owner->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Sonraki Donem',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-06-15',
        ]));
        $owner->followRedirect();
        $html = (string) $owner->getResponse()->getContent();
        $nextReference = $this->activateReference($html);
        $laterToken = (string) $owner->getCrawler()->filter('form[action$="/'.$nextReference.'/aktiflestir"] input[name="_token"]')->attr('value');
        $owner->request('POST', '/kurum/akademik-yillar/'.$pilotReference.'/aktiflestir', [
            '_token' => $laterToken,
            'confirm' => '1',
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('aktifleştirilemez', (string) $owner->getResponse()->getContent());
        self::assertSame('active', $this->yearStatus('Pilot Donemi'));
        self::assertSame('planned', $this->yearStatus('Sonraki Donem'));
        self::assertSame(1, $this->auditCount('academic_year_activated'));

        $crawler = $owner->request('GET', '/kurum/akademik-yillar');
        $owner->submit($owner->getCrawler()->filter('form[action$="/'.$nextReference.'/aktiflestir"]')->form(['confirm' => '1']));
        $owner->followRedirect();
        self::assertSame('closed', $this->yearStatus('Pilot Donemi'));
        self::assertSame('active', $this->yearStatus('Sonraki Donem'));
        self::assertSame(1, $this->auditCount('academic_year_closed'));
        $closed = $this->latestAudit('academic_year_closed');
        self::assertSame('academic_year_manager', $closed['source'] ?? null);
        self::assertSame('panel_year_activate', $closed['reason_code'] ?? null);
        self::assertSame('active', $closed['old_status'] ?? null);
        self::assertSame('closed', $closed['new_status'] ?? null);
        $html = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Kapandı', $html);
        self::assertStringNotContainsString('Sil', $html);

        $owner->request('GET', '/kurum/siniflar/yeni');
        $classroom = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('Sonraki Donem', $classroom);
        self::assertStringNotContainsString('Pilot Donemi', $classroom);

        $manager = $this->browser();
        $this->login($manager, 'year-manager@example.com');
        $crawler = $manager->request('GET', '/kurum/akademik-yillar');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Bora Koleji', (string) $manager->getResponse()->getContent());
        $manager->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Mudur Donemi',
            'starts_on' => '2028-09-01',
            'ends_on' => '2029-06-15',
        ]));
        $manager->followRedirect();
        $manager->submit($manager->getCrawler()->filter('form[action$="/aktiflestir"]')->form(['confirm' => '1']));
        $manager->followRedirect();
        self::assertSame('active', $this->yearStatus('Mudur Donemi'));
        self::assertSame('closed', $this->yearStatus('Sonraki Donem'));
        $manager->request('GET', '/kurum/siniflar/yeni');
        $classroom = (string) $manager->getResponse()->getContent();
        self::assertStringContainsString('Mudur Donemi', $classroom);
        self::assertStringNotContainsString('Sonraki Donem', $classroom);
        self::assertStringNotContainsString('Pilot Donemi', $classroom);
    }

    public function testInvalidCsrfFieldsAndTransitionsDoNotChangeRecords(): void
    {
        $this->createActive('year-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('year-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->openInstitution('year-owner@example.com', 'Ada Koleji');
        $client = $this->browser();
        $this->login($client, 'year-owner@example.com');

        $client->request('POST', '/kurum/akademik-yillar', [
            '_token' => 'bad',
            'name' => 'Sahte Donem',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-15',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->yearCount());
        self::assertSame(0, $this->auditCount('academic_year_created'));

        $crawler = $client->request('GET', '/kurum/akademik-yillar');
        $client->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Ters Tarih',
            'starts_on' => '2027-06-15',
            'ends_on' => '2026-09-01',
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Bitiş, başlangıçtan önce olamaz.', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->yearCount());
        self::assertSame(0, $this->auditCount('academic_year_created'));

        $crawler = $client->request('GET', '/kurum/akademik-yillar');
        $client->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Pilot Donemi',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-15',
        ]));
        $client->followRedirect();
        $created = $this->auditCount('academic_year_created');
        $html = (string) $client->getResponse()->getContent();
        $reference = $this->activateReference($html);

        $crawler = $client->request('GET', '/kurum/akademik-yillar');
        $client->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Cakisan Donem',
            'starts_on' => '2026-10-01',
            'ends_on' => '2027-01-15',
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('çakışıyor', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->yearCount());
        self::assertSame($created, $this->auditCount('academic_year_created'));

        $crawler = $client->request('GET', '/kurum/akademik-yillar');
        $client->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'pilot donemi',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-06-15',
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Bu adla bir dönem zaten var.', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->yearCount());
        self::assertSame($created, $this->auditCount('academic_year_created'));

        $client->request('POST', '/kurum/akademik-yillar/'.$reference.'/aktiflestir', [
            '_token' => 'bad',
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('planned', $this->yearStatus('Pilot Donemi'));
        self::assertSame(0, $this->auditCount('academic_year_activated'));

        $client->request('GET', '/kurum/akademik-yillar/'.$reference.'/aktiflestir');
        self::assertResponseStatusCodeSame(405);
        self::assertSame('planned', $this->yearStatus('Pilot Donemi'));
        self::assertSame(0, $this->auditCount('academic_year_activated'));

        $crawler = $client->request('GET', '/kurum/akademik-yillar');
        $client->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Sonraki Donem',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-06-15',
        ]));
        $client->followRedirect();
        $nextReference = $this->activateReference((string) $client->getResponse()->getContent());
        $this->closeYear('year-owner@example.com', 'Pilot Donemi');
        $closed = $this->auditCount('academic_year_closed');
        $createdAfter = $this->auditCount('academic_year_created');
        $crawler = $client->request('GET', '/kurum/akademik-yillar');
        self::assertStringContainsString('Kapandı', (string) $client->getResponse()->getContent());
        $token = (string) $crawler->filter('form[action$="/'.$nextReference.'/aktiflestir"] input[name="_token"]')->attr('value');
        $client->request('POST', '/kurum/akademik-yillar/'.$reference.'/aktiflestir', [
            '_token' => $token,
            'confirm' => '1',
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('aktifleştirilemez', (string) $client->getResponse()->getContent());
        self::assertSame('closed', $this->yearStatus('Pilot Donemi'));
        self::assertSame('planned', $this->yearStatus('Sonraki Donem'));
        self::assertSame(0, $this->auditCount('academic_year_activated'));
        self::assertSame($closed, $this->auditCount('academic_year_closed'));
        self::assertSame($createdAfter, $this->auditCount('academic_year_created'));
        self::assertSame(2, $this->yearCount());
    }

    public function testUnauthorizedActorsCannotReadOrChangeAnotherInstitutionYear(): void
    {
        $this->createActive('year-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('year-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('year-teacher@example.com', UserRole::Teacher, 'Ece', 'Öztürk');
        $this->createActive('year-student@example.com', UserRole::Student, 'Can', 'Aydın');
        $this->createActive('year-staff@example.com', UserRole::User, 'Selin', 'Arslan');
        $this->createActive('year-other@example.com', UserRole::User, 'Bora', 'Demir');
        $this->createActive('year-global@example.com', UserRole::Teacher);
        $this->openInstitution('year-owner@example.com', 'Ada Koleji');
        $this->openInstitution('year-other@example.com', 'Bora Koleji');
        $this->addMember('year-owner@example.com', 'year-teacher@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');
        $this->addMember('year-owner@example.com', 'year-student@example.com', InstitutionMembershipRole::Student, 'Ada Koleji');
        $this->addMember('year-owner@example.com', 'year-staff@example.com', InstitutionMembershipRole::Staff, 'Ada Koleji');

        $owner = $this->browser();
        $this->login($owner, 'year-owner@example.com');
        $crawler = $owner->request('GET', '/kurum/akademik-yillar');
        $owner->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Pilot Donemi',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-15',
        ]));
        $owner->followRedirect();
        $reference = $this->activateReference((string) $owner->getResponse()->getContent());
        $created = $this->auditCount('academic_year_created');

        $anonymous = $this->browser();
        $anonymous->request('GET', '/kurum/akademik-yillar');
        self::assertResponseRedirects('/giris');
        $anonymous->request('POST', '/kurum/akademik-yillar', ['name' => 'Gizli']);
        self::assertResponseRedirects('/giris');

        foreach ([
            'year-teacher@example.com',
            'year-student@example.com',
            'year-staff@example.com',
            'year-global@example.com',
        ] as $email) {
            $client = $this->browser();
            $this->login($client, $email);
            $client->request('GET', '/kurum/akademik-yillar');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/kurum/akademik-yillar', [
                'name' => 'Gizli Donem',
                'starts_on' => '2028-09-01',
                'ends_on' => '2029-06-15',
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/kurum/akademik-yillar/'.$reference.'/aktiflestir', ['confirm' => '1']);
            self::assertResponseStatusCodeSame(403);
        }

        $other = $this->browser();
        $this->login($other, 'year-other@example.com');
        $crawler = $other->request('GET', '/kurum/akademik-yillar');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Pilot Donemi', (string) $other->getResponse()->getContent());
        $other->submit($crawler->selectButton('Dönemi oluştur')->form([
            'name' => 'Bora Donemi',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-15',
        ]));
        $other->followRedirect();
        $page = (string) $other->getResponse()->getContent();
        self::assertStringContainsString('Bora Donemi', $page);
        self::assertStringNotContainsString('Pilot Donemi', $page);
        self::assertStringNotContainsString($reference, $page);
        $token = (string) $other->getCrawler()->filter('form[action$="/aktiflestir"] input[name="_token"]')->attr('value');
        $other->request('POST', '/kurum/akademik-yillar/'.$reference.'/aktiflestir', [
            '_token' => $token,
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('planned', $this->yearStatus('Pilot Donemi'));
        self::assertSame('planned', $this->yearStatus('Bora Donemi'));
        self::assertSame($created + 1, $this->auditCount('academic_year_created'));
        self::assertSame(0, $this->auditCount('academic_year_activated'));
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function activateReference(string $html): string
    {
        if (1 === preg_match('#/kurum/akademik-yillar/([0-9a-f]{20})/aktiflestir#', $html, $matches)) {
            return $matches[1];
        }

        self::fail('Activate reference was not rendered.');
    }

    private function yearCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM academic_years');
    }

    private function auditCount(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', [$action]);
    }

    private function yearStatus(string $name): string
    {
        return (string) $this->scalar('SELECT status FROM academic_years WHERE name = ?', [$name]);
    }

    private function yearInstitution(string $name): string
    {
        return (string) $this->scalar(
            'SELECT i.name FROM academic_years y INNER JOIN institutions i ON i.id = y.institution_id WHERE y.name = ?',
            [$name],
        );
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

    /**
     * @return array<string, mixed>
     */
    private function latestAudit(string $action): array
    {
        $raw = $this->scalar(
            'SELECT metadata FROM security_audit_events WHERE action = ? ORDER BY occurred_at DESC LIMIT 1',
            [$action],
        );
        $decoded = json_decode((string) $raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function closeYear(string $actorEmail, string $yearName): void
    {
        $this->withKernel(function () use ($actorEmail, $yearName): void {
            $years = static::getContainer()->get(AcademicYearRepository::class);
            $manager = static::getContainer()->get(AcademicYearManager::class);
            self::assertInstanceOf(AcademicYearRepository::class, $years);
            self::assertInstanceOf(AcademicYearManager::class, $manager);
            $year = $years->findOneBy(['name' => $yearName]);
            self::assertInstanceOf(AcademicYear::class, $year);
            $manager->close($year, $this->user($actorEmail), 'test_close');
        });
    }

    private function institutionId(string $name): string
    {
        $id = $this->withKernel(function () use ($name): string {
            return $this->institution($name)->getId()->toRfc4122();
        });

        return \is_string($id) ? $id : '';
    }

    private function openInstitution(string $ownerEmail, string $name): void
    {
        $this->withKernel(function () use ($ownerEmail, $name): void {
            $creator = static::getContainer()->get(InstitutionCreator::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            $creator->create($this->user('year-sa@example.com'), $this->user($ownerEmail), $name, InstitutionType::School, 'setup');
        });
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->activate($this->institution($name), $this->user('year-sa@example.com'), 'activate');
        });
    }

    private function addMember(string $actorEmail, string $subjectEmail, InstitutionMembershipRole $role, string $institutionName): void
    {
        $this->withKernel(function () use ($actorEmail, $subjectEmail, $role, $institutionName): void {
            $manager = static::getContainer()->get(InstitutionMembershipManager::class);
            self::assertInstanceOf(InstitutionMembershipManager::class, $manager);
            $manager->addMember($this->institution($institutionName), $this->user($actorEmail), $this->user($subjectEmail), $role, 'add_member');
        });
    }

    private function institution(string $name): Institution
    {
        $repo = static::getContainer()->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $repo);
        $institution = $repo->findOneBy(['name' => $name]);
        self::assertInstanceOf(Institution::class, $institution);

        return $institution;
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

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function createActive(string $email, UserRole $role, string $first = 'Ayşe', string $last = 'Yılmaz'): void
    {
        $this->withKernel(static function () use ($email, $role, $first, $last): void {
            $factory = static::getContainer()->get(UserFactory::class);
            $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
            $users = static::getContainer()->get(UserRepository::class);
            self::assertInstanceOf(UserFactory::class, $factory);
            self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
            self::assertInstanceOf(UserRepository::class, $users);
            $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
            $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
            if (UserStatus::PendingVerification === $user->getStatus()) {
                $lifecycle->markEmailVerifiedAndActivate($user);
            }
            if ($initial !== $role) {
                $user->addGlobalRole($role);
                $users->save($user);
            }
        });
    }

    private function purge(): void
    {
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            QuestionBankDbCleanup::deleteTables($em->getConnection(), [
                'academic_year_student_enrollment_guards',
                'classroom_student_enrollments',
                'classroom_teacher_active_guards',
                'classroom_homeroom_guards',
                'classroom_teacher_assignments',
                'classrooms',
                'institution_active_academic_year_guards',
                'academic_years',
                'institution_memberships',
                'institutions',
                'student_profiles',
                'security_audit_events',
                'users',
            ]);
            self::ensureKernelShutdown();
        } catch (\Throwable) {
        }
    }
}
