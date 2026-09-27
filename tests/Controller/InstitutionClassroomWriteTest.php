<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InstitutionClassroomWriteTest extends WebTestCase
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

    public function testAnonymousClassroomWritesRedirectToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/kurum/siniflar');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/kurum/siniflar/yeni');
        self::assertResponseRedirects('/giris');
    }

    public function testGlobalRolesCannotCreateAClassroom(): void
    {
        foreach ([
            UserRole::Student,
            UserRole::Parent,
            UserRole::Teacher,
            UserRole::Moderator,
            UserRole::InstitutionManager,
            UserRole::Admin,
            UserRole::SuperAdmin,
        ] as $role) {
            $email = strtolower($role->name).'-cls@example.com';
            $this->createActive($email, $role);
            $client = static::createClient();
            $this->login($client, $email);
            $client->request('POST', '/kurum/siniflar/yeni', ['name' => 'Gizli']);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testOwnerCreatesEditsAndArchivesOnlyInsideTheInstitution(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('bora-owner@example.com', UserRole::User, 'Bora', 'Demir');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->openInstitution('bora-owner@example.com', 'Bora Koleji');
        $this->openYear('ada-owner@example.com', 'Ada Koleji', 'Ada Donemi');
        $this->openYear('bora-owner@example.com', 'Bora Koleji', 'Bora Donemi');

        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $crawler = $client->request('GET', '/kurum/siniflar');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz sınıf oluşturulmamış.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorExists('#institution-mobile-nav[hidden]');
        self::assertSelectorExists('a[aria-current="page"]');

        $crawler = $client->request('GET', '/kurum/siniflar/yeni');
        self::assertStringContainsString('Ada Donemi', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Bora Donemi', (string) $client->getResponse()->getContent());
        $year = (string) $crawler->filter('#classroom-year option')->eq(1)->attr('value');
        $client->submit($crawler->selectButton('Sınıfı oluştur')->form([
            'name' => 'Bes A',
            'grade_level' => '5',
            'section_code' => 'A',
            'capacity' => '24',
            'year_reference' => $year,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Bes A', $html);
        self::assertStringContainsString('5. sınıf', $html);
        self::assertStringContainsString('Aktif', $html);
        self::assertStringContainsString('Bu sınıfta öğretmen yok.', $html);
        self::assertStringContainsString('Bu sınıfta öğrenci yok.', $html);
        self::assertStringContainsString('geri alınamaz', $html);
        self::assertStringNotContainsString('secret', $html);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html);
        self::assertStringNotContainsString('ROLE_', $html);

        $client->request('POST', '/kurum/siniflar/yeni', [
            'name' => 'Bes A',
            'grade_level' => '5',
            'year_reference' => $year,
            '_token' => 'bad',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/kurum/siniflar/yeni');
        $client->submit($crawler->selectButton('Sınıfı oluştur')->form([
            'name' => 'Bes A',
            'grade_level' => '5',
            'year_reference' => $year,
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sınıf kaydedilemedi', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/kurum/siniflar');
        $edit = $crawler->selectLink('Düzenle')->link();
        $crawler = $client->click($edit);
        $token = (string) $crawler->filter('input[name="updated_at"]')->attr('value');
        $client->submit($crawler->selectButton('Kaydet')->form([
            'name' => 'Bes B',
            'capacity' => '20',
            'updated_at' => $token,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Bes B', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', $edit->getUri());
        $client->submit($crawler->selectButton('Kaydet')->form([
            'name' => 'Eski ad',
            'capacity' => '20',
            'updated_at' => '1',
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sınıf kaydedilemedi', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Eski ad', (string) $client->getResponse()->getContent());
        $client->request('GET', '/kurum/siniflar');
        self::assertStringContainsString('Bes B', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Eski ad', (string) $client->getResponse()->getContent());

        self::ensureKernelShutdown();
        $other = static::createClient();
        $this->login($other, 'bora-owner@example.com');
        $otherPage = $other->request('GET', '/kurum/siniflar/yeni');
        $otherYear = (string) $otherPage->filter('#classroom-year option')->eq(1)->attr('value');
        $other->submit($otherPage->selectButton('Sınıfı oluştur')->form([
            'name' => 'Bes B',
            'grade_level' => '5',
            'year_reference' => $otherYear,
        ]));
        self::assertResponseRedirects();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $crawler = $client->request('GET', '/kurum/siniflar');
        $archiveToken = (string) $client->request('GET', $crawler->selectLink('Detay')->link()->getUri())
            ->filter('form[action*="/arsivle"] input[name="_token"]')->attr('value');
        $action = (string) $client->getCrawler()->filter('form[action*="/arsivle"]')->attr('action');
        $client->request('POST', $action, ['_token' => $archiveToken]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Arşiv', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Sınıfı arşivle', (string) $client->getResponse()->getContent());
        $client->request('GET', str_replace('/arsivle', '/duzenle', $action));
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/kurum/siniflar');
        self::assertStringContainsString('Henüz sınıf oluşturulmamış.', (string) $client->getResponse()->getContent());
        $client->request('GET', '/kurum/siniflar?durum=arsiv');
        self::assertStringContainsString('Bes B', (string) $client->getResponse()->getContent());
        $client->request('POST', $action, ['_token' => $archiveToken]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Bu sınıf arşivlenemez.', (string) $client->getResponse()->getContent());
    }

    public function testManagerCanCreateAndTeacherMembershipCannot(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('manager-user@example.com', UserRole::User, 'Mert', 'Sönmez');
        $this->createActive('staff-user@example.com', UserRole::User, 'Seda', 'Ak');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->openYear('ada-owner@example.com', 'Ada Koleji', 'Ada Donemi');
        $this->addMember('ada-owner@example.com', 'manager-user@example.com', InstitutionMembershipRole::Manager, 'Ada Koleji');
        $this->addMember('ada-owner@example.com', 'staff-user@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');

        $client = static::createClient();
        $this->login($client, 'manager-user@example.com');
        $crawler = $client->request('GET', '/kurum/siniflar/yeni');
        $year = (string) $crawler->filter('#classroom-year option')->eq(1)->attr('value');
        $client->submit($crawler->selectButton('Sınıfı oluştur')->form([
            'name' => 'Yonetici Sinifi',
            'grade_level' => '4',
            'year_reference' => $year,
        ]));
        self::assertResponseRedirects();

        self::ensureKernelShutdown();
        $staff = static::createClient();
        $this->login($staff, 'staff-user@example.com');
        $staff->request('GET', '/kurum/siniflar/yeni');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEndedMembershipCannotCreate(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('manager-user@example.com', UserRole::User, 'Mert', 'Sönmez');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->addMember('ada-owner@example.com', 'manager-user@example.com', InstitutionMembershipRole::Manager, 'Ada Koleji');
        $this->endMembership('manager-user@example.com', 'Ada Koleji', 'ada-owner@example.com');

        $client = static::createClient();
        $this->login($client, 'manager-user@example.com');
        $client->request('GET', '/kurum/siniflar/yeni');
        self::assertResponseStatusCodeSame(403);
    }

    public function testArchiveKeepsAssignmentsAndThereIsNoHardDelete(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->createActive('secret-teacher@example.com', UserRole::User, 'Deniz', 'Kaya');
        $this->createActive('other-student@example.com', UserRole::Student, 'Ece', 'Ak');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->openYear('ada-owner@example.com', 'Ada Koleji', 'Ada Donemi');
        $this->addMember('ada-owner@example.com', 'secret-teacher@example.com', InstitutionMembershipRole::Teacher, 'Ada Koleji');
        $this->addMember('ada-owner@example.com', 'other-student@example.com', InstitutionMembershipRole::Student, 'Ada Koleji');
        $this->seedClassroomWithPeople();

        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $crawler = $client->request('GET', '/kurum/siniflar');
        $page = $client->request('GET', $crawler->selectLink('Detay')->link()->getUri());
        $html = (string) $page->html();
        self::assertStringContainsString('Deniz Kaya', $html);
        self::assertStringContainsString('Ece Ak', $html);
        self::assertStringNotContainsString('secret-teacher@example.com', $html);
        self::assertStringNotContainsString('other-student@example.com', $html);
        $token = (string) $page->filter('input[name="_token"]')->attr('value');
        $action = (string) $page->filter('form[action*="/arsivle"]')->attr('action');
        $client->request('POST', $action, ['_token' => $token]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $after = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Deniz Kaya', $after);
        self::assertStringContainsString('Ece Ak', $after);
        $counts = $this->counts();
        self::assertSame(1, $counts['classrooms']);
        self::assertSame(1, $counts['teachers']);
        self::assertSame(1, $counts['students']);
        $client->request('DELETE', $action);
        self::assertResponseStatusCodeSame(405);
    }

    public function testSuspendedInstitutionCannotOpenTheForm(): void
    {
        $this->createActive('workspace-sa@example.com', UserRole::SuperAdmin);
        $this->createActive('ada-owner@example.com', UserRole::User, 'Ada', 'Yılmaz');
        $this->openInstitution('ada-owner@example.com', 'Ada Koleji');
        $this->suspend('Ada Koleji');
        $client = static::createClient();
        $this->login($client, 'ada-owner@example.com');
        $client->request('GET', '/kurum/siniflar/yeni');
        self::assertResponseStatusCodeSame(403);
    }

    private function seedClassroomWithPeople(): void
    {
        $this->withKernel(function (): void {
            $years = static::getContainer()->get(AcademicYearManager::class);
            $classrooms = static::getContainer()->get(ClassroomManager::class);
            $teachers = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
            $students = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
            $memberships = static::getContainer()->get(InstitutionMembershipRepository::class);
            self::assertInstanceOf(AcademicYearManager::class, $years);
            self::assertInstanceOf(ClassroomManager::class, $classrooms);
            self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $teachers);
            self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $students);
            self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
            $owner = $this->user('ada-owner@example.com');
            $institution = $this->institution('Ada Koleji');
            $year = $years->createPlanned(
                $institution,
                $owner,
                'Yil Iki',
                new \DateTimeImmutable('2026-09-01'),
                new \DateTimeImmutable('2027-06-15'),
                'seed_year',
            );
            $classroom = $classrooms->create($year, $owner, 'Tohum Sinifi', GradeLevel::Grade3, 'seed_cls', 'A', 20);
            $teacherMembership = $memberships->findMembership($this->user('secret-teacher@example.com'), $institution);
            $studentMembership = $memberships->findMembership($this->user('other-student@example.com'), $institution);
            self::assertInstanceOf(InstitutionMembership::class, $teacherMembership);
            self::assertInstanceOf(InstitutionMembership::class, $studentMembership);
            $teachers->assign($classroom, $owner, $teacherMembership, TeacherAssignmentRole::AssistantTeacher, 'seed_teacher');
            $students->enroll($classroom, $owner, $studentMembership, 'seed_student');
        });
    }

    /**
     * @return array{classrooms: int, teachers: int, students: int}
     */
    private function counts(): array
    {
        $counts = ['classrooms' => 0, 'teachers' => 0, 'students' => 0];
        $this->withKernel(static function () use (&$counts): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $counts = [
                'classrooms' => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM classrooms'),
                'teachers' => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM classroom_teacher_assignments'),
                'students' => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM classroom_student_enrollments'),
            ];
        });

        return $counts;
    }

    private function openYear(string $ownerEmail, string $institutionName, string $yearName): void
    {
        $this->withKernel(function () use ($ownerEmail, $institutionName, $yearName): void {
            $years = static::getContainer()->get(AcademicYearManager::class);
            self::assertInstanceOf(AcademicYearManager::class, $years);
            $years->createPlanned(
                $this->institution($institutionName),
                $this->user($ownerEmail),
                $yearName,
                new \DateTimeImmutable('2025-09-01'),
                new \DateTimeImmutable('2026-06-15'),
                'create_year',
            );
        });
    }

    private function openInstitution(string $ownerEmail, string $name): void
    {
        $this->withKernel(function () use ($ownerEmail, $name): void {
            $creator = static::getContainer()->get(InstitutionCreator::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            $creator->create($this->user('workspace-sa@example.com'), $this->user($ownerEmail), $name, InstitutionType::School, 'setup');
        });
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->activate($this->institution($name), $this->user('workspace-sa@example.com'), 'activate');
        });
    }

    private function suspend(string $name): void
    {
        $this->withKernel(function () use ($name): void {
            $status = static::getContainer()->get(InstitutionStatusManager::class);
            self::assertInstanceOf(InstitutionStatusManager::class, $status);
            $status->suspend($this->institution($name), $this->user('workspace-sa@example.com'), 'suspend');
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

    private function endMembership(string $subjectEmail, string $institutionName, string $actorEmail): void
    {
        $this->withKernel(function () use ($subjectEmail, $institutionName, $actorEmail): void {
            $memberships = static::getContainer()->get(InstitutionMembershipRepository::class);
            $manager = static::getContainer()->get(InstitutionMembershipManager::class);
            self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
            self::assertInstanceOf(InstitutionMembershipManager::class, $manager);
            $membership = $memberships->findMembership($this->user($subjectEmail), $this->institution($institutionName));
            self::assertInstanceOf(InstitutionMembership::class, $membership);
            $manager->endMembership($membership, $this->user($actorEmail), 'end_member');
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

    private function withKernel(callable $callback): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $callback();
        self::ensureKernelShutdown();
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
