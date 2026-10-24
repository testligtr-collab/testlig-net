<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\TeacherProfessionalProfileRequest;
use App\Entity\Institution;
use App\Entity\Location;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\LocationLevel;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\SubjectManager;
use App\Service\TeacherProfessionalProfileManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Service\UserStatusManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminTeacherDirectoryHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private string $multiId = '';

    private string $studentId = '';

    private string $subjectId = '';

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

    public function testUnauthorizedRolesCannotOpenTheDirectory(): void
    {
        $client = $this->browser();
        $client->request('GET', '/yonetim/ogretmenler');
        self::assertResponseRedirects('/giris');
        $client->request('GET', '/yonetim/ogretmenler/'.$this->multiId);
        self::assertResponseRedirects('/giris');

        foreach (['tdir-student@example.com', 'tdir-parent@example.com', 'tdir-expert@example.com'] as $email) {
            $denied = $this->browser();
            $this->login($denied, $email);
            $denied->request('GET', '/yonetim/ogretmenler');
            self::assertResponseStatusCodeSame(403);
            $denied->request('GET', '/yonetim/ogretmenler/'.$this->multiId);
            self::assertResponseStatusCodeSame(403);
        }

        $admin = $this->browser();
        $this->login($admin, 'tdir-admin@example.com');
        $admin->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/yonetim/ogretmenler"]');
        self::assertSelectorNotExists('a[href="/yonetim/bolum/ogretmenler"]');
    }

    public function testThreeTeacherRolesAreListedOnceAndCountersStayGlobal(): void
    {
        $client = $this->browser();
        $this->login($client, 'tdir-sa@example.com');
        $client->request('GET', '/yonetim/ogretmenler');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame('5', trim($client->getCrawler()->filter('[data-teacher-summary="total"]')->text()));
        self::assertSame('3', trim($client->getCrawler()->filter('[data-teacher-summary="active"]')->text()));
        self::assertSame('4', trim($client->getCrawler()->filter('[data-teacher-summary="verified"]')->text()));
        self::assertSame('1', trim($client->getCrawler()->filter('[data-teacher-summary="withProfile"]')->text()));
        self::assertSame(1, substr_count($html, 'tdir-multi@example.com'));
        self::assertStringContainsString('Uzman öğretmen', $html);
        self::assertStringContainsString('Zümre başkanı', $html);
        self::assertStringContainsString('Tdir Matematik', $html);
        self::assertStringContainsString('5. sınıf, 6. sınıf', $html);
        self::assertStringContainsString('Tdir Ili / Tdir Ilcesi / Tdir Okulu', $html);
        self::assertStringContainsString('Belirtilmemiş', $html);
        self::assertStringNotContainsString('Onaysız', $html);
        self::assertStringNotContainsString('tdir-student@example.com', $html);
        self::assertStringNotContainsString('tdir-parent@example.com', $html);
        self::assertStringNotContainsString('tdir-sa@example.com', $html);
        self::assertStringNotContainsString('GizliOgrenci', $html);

        $client->request('GET', '/yonetim/ogretmenler?q=tdir-missing-zzzz');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Filtrelere uygun öğretmen bulunamadı.', (string) $client->getResponse()->getContent());
        self::assertSame('5', trim($client->getCrawler()->filter('[data-teacher-summary="total"]')->text()));

        $client->request('GET', '/yonetim/ogretmenler?q=tdir-multi');
        self::assertStringContainsString('tdir-multi@example.com', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('tdir-expert@example.com', (string) $client->getResponse()->getContent());
        self::assertSame('5', trim($client->getCrawler()->filter('[data-teacher-summary="total"]')->text()));
    }

    public function testFiltersPaginationAndMissingProfileStayHonest(): void
    {
        $client = $this->browser();
        $this->login($client, 'tdir-sa@example.com');
        $client->request('GET', '/yonetim/ogretmenler?role=ROLE_HEAD_TEACHER');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('tdir-multi@example.com', $html);
        self::assertStringContainsString('tdir-head@example.com', $html);
        self::assertStringNotContainsString('tdir-expert@example.com', $html);
        self::assertSame(1, substr_count($html, 'tdir-multi@example.com'));

        $client->request('GET', '/yonetim/ogretmenler?status=suspended');
        self::assertStringContainsString('tdir-suspended@example.com', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('tdir-multi@example.com', (string) $client->getResponse()->getContent());

        $client->request('GET', '/yonetim/ogretmenler?subject='.$this->subjectId);
        $filtered = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('tdir-multi@example.com', $filtered);
        self::assertStringNotContainsString('tdir-head@example.com', $filtered);

        $client->request('GET', '/yonetim/ogretmenler?page_size=1');
        self::assertSelectorExists('a:contains("Sonraki")');
        $client->request('GET', '/yonetim/ogretmenler?role=ROLE_STUDENT');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/yonetim/ogretmenler/'.$this->studentId);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/yonetim/ogretmenler?q=tdir-expert');
        $client->clickLink('Detay');
        self::assertResponseIsSuccessful();
        $detail = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Belirtilmemiş', $detail);
        self::assertStringNotContainsString('Onaysız', $detail);
        self::assertStringContainsString('Uzman öğretmen', $detail);
    }

    public function testMembershipsFollowPermissionAndSkipStudentData(): void
    {
        $admin = $this->browser();
        $this->login($admin, 'tdir-admin@example.com');
        $admin->request('GET', '/yonetim/ogretmenler/'.$this->multiId);
        self::assertResponseIsSuccessful();
        $adminHtml = (string) $admin->getResponse()->getContent();
        self::assertStringContainsString('Tdir Okulu', $adminHtml);
        self::assertStringNotContainsString('Tdir Kurumu', $adminHtml);
        self::assertStringNotContainsString('GizliOgrenci', $adminHtml);
        self::assertStringContainsString('/yonetim/kullanicilar/'.$this->multiId, $adminHtml);
        self::assertStringNotContainsString('İşlem geçmişi', $adminHtml);
        self::assertStringNotContainsString('classroom_student', $adminHtml);

        $sa = $this->browser();
        $this->login($sa, 'tdir-sa@example.com');
        $sa->request('GET', '/yonetim/ogretmenler/'.$this->multiId);
        self::assertResponseIsSuccessful();
        $html = (string) $sa->getResponse()->getContent();
        self::assertStringContainsString('Tdir Kurumu', $html);
        self::assertStringContainsString('Tdir Sinifi', $html);
        self::assertStringContainsString('Sınıf öğretmeni', $html);
        self::assertStringNotContainsString('Eski Tdir', $html);
        self::assertStringNotContainsString('GizliOgrenci', $html);
        self::assertStringNotContainsString('tdir-student@example.com', $html);
        self::assertStringContainsString('Kullanıcı detayı', $html);
        self::assertStringContainsString('/yonetim/kullanicilar/'.$this->multiId, $html);
        self::assertStringContainsString('İşlem geçmişi', $html);
        self::assertStringContainsString('href="/yonetim/denetim"', $html);
        $this->assertNoEnrollmentQuery($sa, '/yonetim/ogretmenler/'.$this->multiId);
    }

    public function testListQueryCountDoesNotGrowWithRows(): void
    {
        $client = $this->browser();
        $client->disableReboot();
        $this->login($client, 'tdir-sa@example.com');
        $client->request('GET', '/yonetim/ogretmenler');
        self::assertResponseIsSuccessful();
        $small = $this->listQueryCount($client);
        for ($i = 0; $i < 4; ++$i) {
            $this->createAccount('tdir-extra-'.$i.'@example.com', UserRole::Teacher, 'Ek', 'Ogretmen'.$i);
        }
        $large = $this->listQueryCount($client);

        self::assertGreaterThanOrEqual(1, $small['profiles']);
        self::assertSame($small['profiles'], $large['profiles']);
        self::assertSame($small['users'], $large['users']);
        self::assertSame(0, $small['enrollments']);
        self::assertSame(0, $large['enrollments']);
        self::assertSame(0, $small['assignments']);
        self::assertSame(0, $large['assignments']);
    }

    public function testEmptyDirectoryShowsTheEmptyCopy(): void
    {
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            /** @var list<User> $users */
            $users = $em->getRepository(User::class)->findBy([]);
            foreach ($users as $user) {
                if (!str_starts_with($user->getEmail(), 'tdir-')) {
                    continue;
                }
                if ('tdir-sa@example.com' === $user->getEmail() || 'tdir-admin@example.com' === $user->getEmail()) {
                    continue;
                }
                $user->setGlobalRoles([UserRole::Student]);
            }
            $em->flush();
        });

        $client = $this->browser();
        $this->login($client, 'tdir-sa@example.com');
        $client->request('GET', '/yonetim/ogretmenler');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Henüz öğretmen hesabı yok.', (string) $client->getResponse()->getContent());
        self::assertSame('0', trim($client->getCrawler()->filter('[data-teacher-summary="total"]')->text()));
    }

    public function testTeacherCanStillOpenTheProfessionalProfile(): void
    {
        $client = $this->browser();
        $this->login($client, 'tdir-multi@example.com');
        $client->request('GET', '/ogretmen/profil');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tdir Matematik', (string) $client->getResponse()->getContent());
        $client->request('GET', '/yonetim/ogretmenler');
        self::assertResponseStatusCodeSame(403);
    }

    private function seed(): void
    {
        $this->withKernel(function (): void {
            $sa = $this->createAccount('tdir-sa@example.com', UserRole::SuperAdmin, 'Selin', 'Aydin');
            $this->createAccount('tdir-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
            $owner = $this->createAccount('tdir-owner@example.com', UserRole::InstitutionManager, 'Owner', 'Tdir');
            $multi = $this->createAccount('tdir-multi@example.com', UserRole::Teacher, 'Coklu', 'Ogretmen');
            $multi->addGlobalRole(UserRole::ExpertTeacher);
            $multi->addGlobalRole(UserRole::HeadTeacher);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->flush();
            $this->createAccount('tdir-expert@example.com', UserRole::ExpertTeacher, 'Uzman', 'Yalniz');
            $this->createAccount('tdir-head@example.com', UserRole::HeadTeacher, 'Zumre', 'Yalniz');
            $suspended = $this->createAccount('tdir-suspended@example.com', UserRole::Teacher, 'Askida', 'Ogretmen');
            $status = static::getContainer()->get(UserStatusManager::class);
            self::assertInstanceOf(UserStatusManager::class, $status);
            $status->suspend($suspended, $sa, 'tdir_suspend');
            $pending = $this->createAccount('tdir-pending@example.com', UserRole::Teacher, 'Bekleyen', 'Ogretmen', false);
            self::assertSame(UserStatus::PendingVerification, $pending->getStatus());
            $student = $this->createAccount('tdir-student@example.com', UserRole::Student, 'GizliOgrenci', 'Tdir');
            $this->createAccount('tdir-parent@example.com', UserRole::Parent, 'Veli', 'Tdir');

            $subjects = static::getContainer()->get(SubjectManager::class);
            self::assertInstanceOf(SubjectManager::class, $subjects);
            $subject = $subjects->create($sa, 'tdir_mat', 'Tdir Matematik', 'tdir_subject');
            $this->subjectId = $subject->getId()->toRfc4122();

            $now = new \DateTimeImmutable('2026-10-23 12:00:00 UTC');
            $province = Location::create(LocationLevel::Province, 'Tdir Ili', null, $now);
            $district = Location::create(LocationLevel::District, 'Tdir Ilcesi', $province, $now);
            $school = Location::create(LocationLevel::School, 'Tdir Okulu', $district, $now);
            $em->persist($province);
            $em->persist($district);
            $em->persist($school);
            $em->flush();

            $request = new TeacherProfessionalProfileRequest();
            $request->subject = $subject;
            $request->gradeLevels = [GradeLevel::Grade5, GradeLevel::Grade6];
            $request->provinceId = $province->getId()->toRfc4122();
            $request->districtId = $district->getId()->toRfc4122();
            $request->schoolId = $school->getId()->toRfc4122();
            $profiles = static::getContainer()->get(TeacherProfessionalProfileManager::class);
            self::assertInstanceOf(TeacherProfessionalProfileManager::class, $profiles);
            $profiles->save($multi, $request);

            $creator = static::getContainer()->get(InstitutionCreator::class);
            $institutions = static::getContainer()->get(InstitutionRepository::class);
            $institutionStatus = static::getContainer()->get(InstitutionStatusManager::class);
            $members = static::getContainer()->get(InstitutionMembershipManager::class);
            $years = static::getContainer()->get(AcademicYearManager::class);
            $classrooms = static::getContainer()->get(ClassroomManager::class);
            $assignments = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
            $enrollments = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            self::assertInstanceOf(InstitutionRepository::class, $institutions);
            self::assertInstanceOf(InstitutionStatusManager::class, $institutionStatus);
            self::assertInstanceOf(InstitutionMembershipManager::class, $members);
            self::assertInstanceOf(AcademicYearManager::class, $years);
            self::assertInstanceOf(ClassroomManager::class, $classrooms);
            self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $assignments);
            self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $enrollments);

            $creator->create($sa, $owner, 'Tdir Kurumu', InstitutionType::School, 'tdir_create');
            $institution = $institutions->findOneBy(['name' => 'Tdir Kurumu']);
            self::assertInstanceOf(Institution::class, $institution);
            $institutionStatus->activate($institution, $sa, 'tdir_activate');
            $year = $years->createPlanned($institution, $sa, 'Tdir Yili', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'tdir_year');
            $years->activate($year, $sa, 'tdir_year_open');
            $classroom = $classrooms->create($year, $sa, 'Tdir Sinifi', GradeLevel::Grade5, 'tdir_class', 'A', 20);
            $ended = $classrooms->create($year, $sa, 'Eski Tdir', GradeLevel::Grade5, 'tdir_class_old', 'B', 20);
            $teacherMembership = $members->addMember($institution, $sa, $multi, InstitutionMembershipRole::Teacher, 'tdir_member');
            $studentMembership = $members->addMember($institution, $sa, $student, InstitutionMembershipRole::Student, 'tdir_student');
            $assignments->assign($classroom, $sa, $teacherMembership, TeacherAssignmentRole::HomeroomTeacher, 'tdir_assign');
            $oldAssignment = $assignments->assign($ended, $sa, $teacherMembership, TeacherAssignmentRole::AssistantTeacher, 'tdir_assign_old');
            $assignments->endAssignment($oldAssignment, $sa, 'tdir_end');
            $enrollments->enroll($classroom, $sa, $studentMembership, 'tdir_enroll');

            $this->multiId = $multi->getId()->toRfc4122();
            $this->studentId = $student->getId()->toRfc4122();
        });
    }

    private function createAccount(string $email, UserRole $role, string $first, string $last, bool $activate = true): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Student : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
        if ($activate && UserStatus::PendingVerification === $user->getStatus()) {
            $lifecycle->markEmailVerifiedAndActivate($user);
        }
        if ($initial !== $role) {
            $user->setGlobalRoles([$role]);
            $em->flush();
        }

        return $user;
    }

    private function purge(): void
    {
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            $ids = $connection->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'tdir-%'");
            if ([] !== $ids) {
                $connection->executeStatement('DELETE FROM classroom_student_enrollments WHERE institution_id IN (SELECT id FROM institutions WHERE name = ?)', ['Tdir Kurumu']);
                $connection->executeStatement('DELETE FROM classroom_teacher_assignments WHERE institution_id IN (SELECT id FROM institutions WHERE name = ?)', ['Tdir Kurumu']);
                $connection->executeStatement('DELETE FROM classrooms WHERE institution_id IN (SELECT id FROM institutions WHERE name = ?)', ['Tdir Kurumu']);
                $connection->executeStatement('DELETE FROM academic_years WHERE institution_id IN (SELECT id FROM institutions WHERE name = ?)', ['Tdir Kurumu']);
                $connection->executeStatement('DELETE FROM institution_memberships WHERE institution_id IN (SELECT id FROM institutions WHERE name = ?)', ['Tdir Kurumu']);
                $connection->executeStatement('DELETE FROM institutions WHERE name = ?', ['Tdir Kurumu']);
                $connection->executeStatement('DELETE FROM teacher_professional_profiles WHERE user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
                $connection->executeStatement('DELETE FROM security_audit_events WHERE actor_user_id IN (?) OR subject_user_id IN (?)', [$ids, $ids], [ArrayParameterType::BINARY, ArrayParameterType::BINARY]);
                $connection->executeStatement('DELETE FROM users WHERE email LIKE ?', ['tdir-%']);
            }
            $connection->executeStatement('DELETE FROM locations WHERE name = ?', ['Tdir Okulu']);
            $connection->executeStatement('DELETE FROM locations WHERE name = ?', ['Tdir Ilcesi']);
            $connection->executeStatement('DELETE FROM locations WHERE name = ?', ['Tdir Ili']);
            $connection->executeStatement('DELETE FROM subjects WHERE code = ?', ['tdir_mat']);
        });
    }

    /**
     * @return array{profiles: int, users: int, enrollments: int, assignments: int}
     */
    private function listQueryCount(KernelBrowser $client): array
    {
        $holder = $client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $client->request('GET', '/yonetim/ogretmenler');
        self::assertResponseIsSuccessful();

        return $this->countStatements($holder);
    }

    private function assertNoEnrollmentQuery(KernelBrowser $client, string $path): void
    {
        $holder = $client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $counts = $this->countStatements($holder);
        self::assertSame(0, $counts['enrollments']);
    }

    /**
     * @return array{profiles: int, users: int, enrollments: int, assignments: int}
     */
    private function countStatements(DebugDataHolder $holder): array
    {
        $counts = ['profiles' => 0, 'users' => 0, 'enrollments' => 0, 'assignments' => 0];
        foreach ($holder->getData() as $queries) {
            if (!\is_array($queries)) {
                continue;
            }
            foreach ($queries as $item) {
                $statement = \is_array($item) ? ($item['sql'] ?? null) : null;
                if (!\is_string($statement)) {
                    continue;
                }
                if (str_contains($statement, 'teacher_professional_profiles')) {
                    ++$counts['profiles'];
                }
                if (str_contains($statement, 'users')) {
                    ++$counts['users'];
                }
                if (str_contains($statement, 'classroom_student_enrollments')) {
                    ++$counts['enrollments'];
                }
                if (str_contains($statement, 'classroom_teacher_assignments')) {
                    ++$counts['assignments'];
                }
            }
        }

        return $counts;
    }

    private function withKernel(callable $callback): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            $callback();
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
}
