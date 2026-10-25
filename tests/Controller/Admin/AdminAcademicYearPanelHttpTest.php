<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Dto\AdminAcademicYearActivateRequest;
use App\Dto\AdminAcademicYearCreateRequest;
use App\Entity\AcademicYear;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Uid\Uuid;

final class AdminAcademicYearPanelHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testClosedRolesCannotOpenTheAcademicYearScreens(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetim/akademik-yillar');
        self::assertResponseRedirects('/giris');

        foreach ([
            ['ay_http_student@example.com', UserRole::Student],
            ['ay_http_teacher@example.com', UserRole::Teacher],
            ['ay_http_manager@example.com', UserRole::InstitutionManager],
            ['ay_http_admin@example.com', UserRole::Admin],
        ] as [$email, $role]) {
            self::assertInstanceOf(UserRole::class, $role);
            self::ensureKernelShutdown();
            $client = static::createClient();
            $user = $this->user($email, $role);
            $this->authenticate($client, $user->getEmail());
            foreach (['/yonetim/akademik-yillar', '/yonetim/akademik-yillar/yeni'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(403);
            }
        }
    }

    public function testInactiveAndUnverifiedSuperAdminAreDenied(): void
    {
        $inactive = $this->user('ay_http_inactive_sa@example.com', UserRole::SuperAdmin);
        $inactive->transitionTo(UserStatus::Suspended);
        $this->save($inactive);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($inactive);
        $client->request('GET', '/yonetim/akademik-yillar');
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $unverified = $this->user('ay_http_unverified_sa@example.com', UserRole::SuperAdmin, false);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($unverified);
        $client->request('GET', '/yonetim/akademik-yillar');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/akademik-yillar', ['admin_academic_year_create' => [
            'name' => 'Gizli',
            'confirm' => '1',
        ]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidCreateKeepsFieldsAndDoesNotWrite(): void
    {
        $client = static::createClient();
        $actor = $this->user('ay_http_form_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('ay_http_form_owner@example.com', UserRole::Teacher);
        $institution = $this->activateInstitution($actor, $owner, 'Ay Http Form');
        $this->authenticate($client, $actor->getEmail());
        $before = $this->yearCount();
        $audits = $this->auditTotal();

        $client->request('POST', '/yonetim/akademik-yillar', ['admin_academic_year_create' => [
            'name' => 'Yarim Kayit',
            'startsOn' => '2026-09-01',
            'endsOn' => '2027-06-30',
        ]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->yearCount());
        self::assertSame($audits, $this->auditTotal());

        $crawler = $client->request('GET', '/yonetim/akademik-yillar/yeni?kurum='.$institution->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Yeni akademik yıl');
        $form = $crawler->selectButton('Yılı oluştur')->form();
        $form['admin_academic_year_create[name]'] = 'Yarim Kayit';
        $form['admin_academic_year_create[startsOn]'] = '2026-09-01';
        $form['admin_academic_year_create[endsOn]'] = '2026-01-01';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('admin_academic_year_create[name]', 'Yarim Kayit');
        self::assertInputValueSame('admin_academic_year_create[startsOn]', '2026-09-01');
        self::assertSelectorTextContains('body', 'Bitiş tarihi başlangıçtan önce olamaz.');
        self::assertSame($before, $this->yearCount());
        self::assertSame($audits, $this->auditTotal());

        $client->request('GET', '/yonetim/bolum/akademik-yillar');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateDoesNotActivateAndActivationStaysInsideTheInstitution(): void
    {
        $client = static::createClient();
        $actor = $this->user('ay_http_write_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('ay_http_write_owner@example.com', UserRole::Teacher);
        $home = $this->activateInstitution($actor, $owner, 'Ay Http Home');
        $other = $this->activateInstitution($actor, $owner, 'Ay Http Other');
        $foreign = $this->years()->createPlanned($other, $actor, 'Diger Http', new \DateTimeImmutable('2024-09-01'), new \DateTimeImmutable('2025-06-30'), 'operator_setup');
        $this->years()->activate($foreign, $actor, 'lifecycle_change');
        $this->authenticate($client, $actor->getEmail());

        $crawler = $client->request('GET', '/yonetim/akademik-yillar/yeni?kurum='.$home->getId()->toRfc4122());
        $form = $crawler->selectButton('Yılı oluştur')->form();
        $form['admin_academic_year_create[institutionId]'] = $home->getId()->toRfc4122();
        $form['admin_academic_year_create[name]'] = 'Http Donem';
        $form['admin_academic_year_create[startsOn]'] = '2024-09-01';
        $form['admin_academic_year_create[endsOn]'] = '2025-06-30';
        $form['admin_academic_year_create[reasonCode]'] = AdminAcademicYearCreateRequest::REASON_ONBOARDING;
        $confirm = $form['admin_academic_year_create[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->tick();
        $client->submit($form);
        self::assertResponseRedirects();
        $created = $this->findByName($home, 'Http Donem');
        self::assertSame(AcademicYearStatus::Planned, $created->getStatus());
        self::assertSame(0, $this->auditCount('academic_year_activated', $created->getId()->toRfc4122()));

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Bu kurumda aktif akademik yıl yok.');
        $activate = $client->getCrawler()->selectButton('Yılı aktifleştir')->form();
        $activate['admin_academic_year_activate[reasonCode]'] = AdminAcademicYearActivateRequest::REASON_LIFECYCLE;
        $activateConfirm = $activate['admin_academic_year_activate[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $activateConfirm);
        $activateConfirm->tick();
        $client->submit($activate);
        self::assertResponseRedirects();
        self::assertSame(AcademicYearStatus::Active, $this->reload($created->getId())->getStatus());

        $crawler = $client->request('GET', '/yonetim/akademik-yillar/yeni?kurum='.$home->getId()->toRfc4122());
        $next = $crawler->selectButton('Yılı oluştur')->form();
        $next['admin_academic_year_create[institutionId]'] = $other->getId()->toRfc4122();
        $next['admin_academic_year_create[name]'] = 'Http Donem';
        $next['admin_academic_year_create[startsOn]'] = '2024-09-01';
        $next['admin_academic_year_create[endsOn]'] = '2025-06-30';
        $next['admin_academic_year_create[reasonCode]'] = AdminAcademicYearCreateRequest::REASON_ONBOARDING;
        $nextConfirm = $next['admin_academic_year_create[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $nextConfirm);
        $nextConfirm->tick();
        $posted = $next->getPhpValues();
        $posted['admin_academic_year_create']['institutionId'] = $home->getId()->toRfc4122();
        $posted['admin_academic_year_create']['name'] = 'Http Sonraki';
        $posted['admin_academic_year_create']['startsOn'] = '2025-09-01';
        $posted['admin_academic_year_create']['endsOn'] = '2026-06-30';
        $client->request('POST', '/yonetim/akademik-yillar', $posted);
        self::assertResponseRedirects();
        $second = $this->findByName($home, 'Http Sonraki');
        self::assertSame($home->getId()->toRfc4122(), $second->getInstitution()->getId()->toRfc4122());
        self::assertSame(AcademicYearStatus::Planned, $second->getStatus());

        $page = $client->request('GET', '/yonetim/akademik-yillar/'.$second->getId()->toRfc4122());
        self::assertSelectorTextContains('body', 'Mevcut aktif yıl: Http Donem');
        $switch = $page->selectButton('Yılı aktifleştir')->form();
        $switch['admin_academic_year_activate[reasonCode]'] = AdminAcademicYearActivateRequest::REASON_LIFECYCLE;
        $switchConfirm = $switch['admin_academic_year_activate[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $switchConfirm);
        $switchConfirm->tick();
        $values = $switch->getPhpValues();
        $values['admin_academic_year_activate']['institutionId'] = $other->getId()->toRfc4122();
        $client->request('POST', '/yonetim/akademik-yillar/'.$second->getId()->toRfc4122().'/aktiflestir', $values);
        self::assertResponseRedirects();
        self::assertSame(AcademicYearStatus::Closed, $this->reload($created->getId())->getStatus());
        self::assertSame(AcademicYearStatus::Active, $this->reload($second->getId())->getStatus());
        self::assertSame($home->getId()->toRfc4122(), $this->reload($second->getId())->getInstitution()->getId()->toRfc4122());
        self::assertSame(AcademicYearStatus::Active, $this->reload($foreign->getId())->getStatus());
        self::assertSame(1, $this->activeCount($home->getId()));
        self::assertSame(1, $this->activeCount($other->getId()));
    }

    public function testInactiveInstitutionActivationIsRejectedAndInstitutionPanelStaysOpen(): void
    {
        $client = static::createClient();
        $actor = $this->user('ay_http_block_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('ay_http_block_owner@example.com', UserRole::Teacher);
        $institution = $this->activateInstitution($actor, $owner, 'Ay Http Block');
        $year = $this->years()->createPlanned($institution, $actor, 'Bekleyen Donem', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'), 'operator_setup');
        self::ensureKernelShutdown();
        $ownerClient = static::createClient();
        $this->authenticate($ownerClient, $owner->getEmail());
        $ownerClient->request('GET', '/kurum/akademik-yillar');
        self::assertResponseIsSuccessful();
        $ownerClient->request('GET', '/yonetim/akademik-yillar');
        self::assertResponseStatusCodeSame(403);

        $this->suspendInstitution($institution->getId(), $actor->getId());
        $audits = $this->auditCount('academic_year_activated', $year->getId()->toRfc4122());
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $actor->getEmail());

        $page = $client->request('GET', '/yonetim/akademik-yillar/'.$year->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aktif olmayan kurumda akademik yıl aktifleştirilemez.');
        $token = $page->filter('input[name="admin_academic_year_activate[_token]"]');
        self::assertCount(0, $token);

        $client->request('POST', '/yonetim/akademik-yillar/'.$year->getId()->toRfc4122().'/aktiflestir', [
            'admin_academic_year_activate' => [
                'reasonCode' => AdminAcademicYearActivateRequest::REASON_LIFECYCLE,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(AcademicYearStatus::Planned, $this->reload($year->getId())->getStatus());
        self::assertSame($audits, $this->auditCount('academic_year_activated', $year->getId()->toRfc4122()));
    }

    private function authenticate(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();
    }

    private function user(string $email, UserRole $role, bool $verified = true): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Soyad', $initial);
        if ($verified) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
            $user->transitionTo(UserStatus::Active);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $this->save($user);

        return $user;
    }

    private function save(User $user): void
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);
    }

    private function activateInstitution(User $actor, User $owner, string $name): Institution
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        $institution = $creator->create($actor, $owner, $name, InstitutionType::School, 'platform_setup');
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $status->activate($institution, $actor, 'operator_setup');

        return $institution;
    }

    private function years(): AcademicYearManager
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $service = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $service);

        return $service;
    }

    private function institutionStatus(): InstitutionStatusManager
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $service = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $service);

        return $service;
    }

    private function findByName(Institution $institution, string $name): AcademicYear
    {
        $year = $this->em()->createQuery(
            'SELECT y FROM App\Entity\AcademicYear y WHERE IDENTITY(y.institution) = :institution AND y.name = :name',
        )
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('name', $name)
            ->getOneOrNullResult();
        self::assertInstanceOf(AcademicYear::class, $year);

        return $year;
    }

    private function suspendInstitution(Uuid $institutionId, Uuid $actorId): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $institution = $em->find(Institution::class, $institutionId);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(Institution::class, $institution);
        self::assertInstanceOf(UserRepository::class, $users);
        $actor = $users->findOneById($actorId);
        self::assertInstanceOf(User::class, $actor);
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $status->suspend($institution, $actor, 'operator_correction');
    }

    private function reload(Uuid $id): AcademicYear
    {
        $em = $this->em();
        $em->clear();
        $year = $em->find(AcademicYear::class, $id);
        self::assertInstanceOf(AcademicYear::class, $year);

        return $year;
    }

    private function yearCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM academic_years');
    }

    private function activeCount(Uuid $institutionId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM academic_years WHERE institution_id = ? AND status = ?',
            [$institutionId->toBinary(), AcademicYearStatus::Active->value],
        );
    }

    private function auditTotal(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
    }

    private function auditCount(string $action, string $yearId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = ? AND metadata LIKE ?',
            [$action, '%'.$yearId.'%'],
        );
    }

    private function em(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
