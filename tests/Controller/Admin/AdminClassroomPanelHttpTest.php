<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\ClassroomStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Uid\Uuid;

final class AdminClassroomPanelHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testClosedRolesCannotOpenTheClassroomScreens(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetim/siniflar');
        self::assertResponseRedirects('/giris');

        foreach ([
            ['cr_http_student@example.com', UserRole::Student],
            ['cr_http_teacher@example.com', UserRole::Teacher],
            ['cr_http_manager@example.com', UserRole::InstitutionManager],
            ['cr_http_admin@example.com', UserRole::Admin],
        ] as [$email, $role]) {
            self::assertInstanceOf(UserRole::class, $role);
            self::ensureKernelShutdown();
            $client = static::createClient();
            $user = $this->user($email, $role);
            $this->authenticate($client, $user->getEmail());
            foreach (['/yonetim/siniflar', '/yonetim/siniflar/yeni'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(403);
            }
            $client->request('POST', '/yonetim/siniflar', ['admin_classroom_create' => ['name' => 'Gizli', 'confirm' => '1']]);
            self::assertResponseStatusCodeSame(403);
        }

        self::ensureKernelShutdown();
        $client = static::createClient();
        $super = $this->user('cr_http_planned_sa@example.com', UserRole::SuperAdmin);
        $this->authenticate($client, $super->getEmail());
        $client->request('GET', '/yonetim/bolum/siniflar');
        self::assertResponseStatusCodeSame(404);
    }

    public function testInactiveAndUnverifiedSuperAdminAreDenied(): void
    {
        $inactive = $this->user('cr_http_inactive_sa@example.com', UserRole::SuperAdmin);
        $inactive->transitionTo(UserStatus::Suspended);
        $this->save($inactive);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($inactive);
        $client->request('GET', '/yonetim/siniflar');
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $unverified = $this->user('cr_http_unverified_sa@example.com', UserRole::SuperAdmin, false);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($unverified);
        $client->request('GET', '/yonetim/siniflar');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/siniflar', ['admin_classroom_create' => [
            'name' => 'Gizli',
            'confirm' => '1',
        ]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidCreateMismatchAndMissingCsrfDoNotWrite(): void
    {
        $client = static::createClient();
        $actor = $this->user('cr_http_form_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('cr_http_form_owner@example.com', UserRole::Teacher);
        $home = $this->activateInstitution($actor, $owner, 'Cr Http Home');
        $other = $this->activateInstitution($actor, $owner, 'Cr Http Other');
        $homeYear = $this->openYear($home, $actor, 'Cr Http Home Yil');
        $otherYear = $this->openYear($other, $actor, 'Cr Http Other Yil');
        $this->authenticate($client, $actor->getEmail());
        $before = $this->classroomCount();
        $audits = $this->auditTotal();

        $client->request('POST', '/yonetim/siniflar', ['admin_classroom_create' => [
            'name' => 'Csrf Yok',
            'confirm' => '1',
        ]]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/yonetim/siniflar/yeni?kurum='.$home->getId()->toRfc4122().'&yil='.$homeYear->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/yonetim/siniflar"]');
        $form = $crawler->selectButton('Sınıfı oluştur')->form();
        $form['admin_classroom_create[name]'] = 'Tarayici Gecici Sinif';
        $form['admin_classroom_create[gradeLevel]'] = '5';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('admin_classroom_create[name]', 'Tarayici Gecici Sinif');
        self::assertSelectorTextContains('body', 'İşlem gerekçesi gerekli.');
        self::assertSame(0, $client->getCrawler()->filterXPath('//*[contains(text(), "İşlem gerekçesi geçersiz.")]')->count());

        $crawler = $client->request('GET', '/yonetim/siniflar/yeni');
        $form = $crawler->selectButton('Sınıfı oluştur')->form();
        $form['admin_classroom_create[institutionId]'] = $home->getId()->toRfc4122();
        $form['admin_classroom_create[academicYearId]'] = $otherYear->getId()->toRfc4122();
        $form['admin_classroom_create[name]'] = 'Yanlis Yil';
        $form['admin_classroom_create[gradeLevel]'] = '5';
        $form['admin_classroom_create[reasonCode]'] = 'operator_setup';
        $confirm = $form['admin_classroom_create[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->tick();
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Akademik yıl seçilen kuruma ait değil.');
        self::assertSame($before, $this->classroomCount());
        self::assertSame($audits, $this->auditTotal());

        $enrollments = $this->countTable('classroom_student_enrollments');
        $memberships = $this->countTable('institution_memberships');
        $crawler = $client->request('GET', '/yonetim/siniflar/yeni');
        $form = $crawler->selectButton('Sınıfı oluştur')->form();
        $form['admin_classroom_create[institutionId]'] = $home->getId()->toRfc4122();
        $form['admin_classroom_create[academicYearId]'] = $homeYear->getId()->toRfc4122();
        $form['admin_classroom_create[name]'] = 'Http Sinif';
        $form['admin_classroom_create[gradeLevel]'] = '5';
        $form['admin_classroom_create[sectionCode]'] = 'H-1';
        $form['admin_classroom_create[reasonCode]'] = 'operator_setup';
        $confirm = $form['admin_classroom_create[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->tick();
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Sınıf detayı');
        self::assertSelectorTextContains('body', 'Http Sinif');
        self::assertSelectorTextContains('body', 'Öğretmen atanmamış');
        self::assertSame($before + 1, $this->classroomCount());
        self::assertSame($enrollments, $this->countTable('classroom_student_enrollments'));
        self::assertSame($memberships, $this->countTable('institution_memberships'));
    }

    public function testReviseIgnoresForeignInstitutionAndYear(): void
    {
        $client = static::createClient();
        $actor = $this->user('cr_http_revise_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('cr_http_revise_owner@example.com', UserRole::Teacher);
        $home = $this->activateInstitution($actor, $owner, 'Cr Revise Home');
        $other = $this->activateInstitution($actor, $owner, 'Cr Revise Other');
        $homeYear = $this->openYear($home, $actor, 'Cr Revise Home Yil');
        $otherYear = $this->openYear($other, $actor, 'Cr Revise Other Yil');
        $classroom = $this->classrooms()->create($homeYear, $actor, 'Revise Sinif', GradeLevel::Grade4, 'operator_setup', 'R', 18);
        $this->authenticate($client, $actor->getEmail());
        $crawler = $client->request('GET', '/yonetim/siniflar/'.$classroom->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="admin_classroom_revise[_token]"]')->attr('value');
        self::assertIsString($token);
        $client->request('POST', '/yonetim/siniflar/'.$classroom->getId()->toRfc4122().'/guncelle', [
            'admin_classroom_revise' => [
                '_token' => $token,
                'name' => 'Tasinan Sinif',
                'capacity' => '18',
                'expectedUpdatedAt' => (string) $classroom->getUpdatedAt()->getTimestamp(),
                'reasonCode' => 'operator_correction',
                'confirm' => '1',
                'institutionId' => $other->getId()->toRfc4122(),
                'academicYearId' => $otherYear->getId()->toRfc4122(),
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        $reloaded = $this->reloadClassroom($classroom->getId());
        self::assertSame('Revise Sinif', $reloaded->getName());
        self::assertSame($home->getId()->toRfc4122(), $reloaded->getInstitution()->getId()->toRfc4122());
        self::assertSame($homeYear->getId()->toRfc4122(), $reloaded->getAcademicYear()->getId()->toRfc4122());
        self::assertSame(ClassroomStatus::Active, $reloaded->getStatus());
    }

    public function testInstitutionAndTeacherPanelsStayInTheirScope(): void
    {
        $actor = $this->user('cr_http_scope_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('cr_http_scope_owner@example.com', UserRole::InstitutionManager);
        $teacher = $this->user('cr_http_scope_teacher@example.com', UserRole::Teacher);
        $home = $this->activateInstitution($actor, $owner, 'Cr Scope Home');
        $year = $this->openYear($home, $owner, 'Cr Scope Yil');
        $classroom = $this->classrooms()->create($year, $owner, 'Scope Sinif', GradeLevel::Grade3, 'operator_setup');
        $membership = $this->memberships()->addMember($home, $owner, $teacher, InstitutionMembershipRole::Teacher, 'operator_setup');
        $this->assignments()->assign($classroom, $owner, $membership, TeacherAssignmentRole::HomeroomTeacher, 'operator_setup');

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $owner->getEmail());
        $client->request('GET', '/kurum/siniflar');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/yonetim/siniflar');
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->authenticate($client, $teacher->getEmail());
        $client->request('GET', '/ogretmen/siniflarim');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/yonetim/siniflar');
        self::assertResponseStatusCodeSame(403);
    }

    private function authenticate(KernelBrowser $client, string $email): void
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $client->loginUser($user);
    }

    private function user(string $email, UserRole $role, bool $verified = true): User
    {
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
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        $institution = $creator->create($actor, $owner, $name, InstitutionType::School, 'platform_setup');
        $status = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $status);
        $status->activate($institution, $actor, 'operator_setup');

        return $institution;
    }

    private function openYear(Institution $institution, User $actor, string $name): AcademicYear
    {
        $years = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $years);
        $year = $years->createPlanned($institution, $actor, $name, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'operator_setup');
        $years->activate($year, $actor, 'lifecycle_change');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $reloaded = $em->find(AcademicYear::class, $year->getId());
        self::assertInstanceOf(AcademicYear::class, $reloaded);

        return $reloaded;
    }

    private function classrooms(): ClassroomManager
    {
        $manager = static::getContainer()->get(ClassroomManager::class);
        self::assertInstanceOf(ClassroomManager::class, $manager);

        return $manager;
    }

    private function memberships(): InstitutionMembershipManager
    {
        $manager = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $manager);

        return $manager;
    }

    private function assignments(): ClassroomTeacherAssignmentManager
    {
        $manager = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
        self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $manager);

        return $manager;
    }

    private function reloadClassroom(Uuid $id): Classroom
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $classroom = $em->find(Classroom::class, $id);
        self::assertInstanceOf(Classroom::class, $classroom);

        return $classroom;
    }

    private function classroomCount(): int
    {
        return $this->countTable('classrooms');
    }

    private function auditTotal(): int
    {
        return $this->countTable('security_audit_events');
    }

    private function countTable(string $table): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
