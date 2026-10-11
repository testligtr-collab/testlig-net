<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Location;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\LocationLevel;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\TeacherProfessionalProfileRepository;
use App\Repository\UserRepository;
use App\Service\TeacherApplicationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class TeacherProfessionalProfileLocationHttpTest extends WebTestCase
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

    public function testTeacherSavesOnlyTheSchoolFromOneActiveChain(): void
    {
        $math = $this->subject('tloc-mat', 'Matematik');
        $science = $this->subject('tloc-fen', 'Fen');
        $home = $this->chain('tloc Il', 'tloc Ilce', 'tloc Okul');
        $this->chain('tloc Baska Il', 'tloc Baska Ilce', 'tloc Baska Okul');
        $this->createActive('tloc-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $beforeRoles = $this->roles('tloc-teacher@example.com');
        $beforeInstitutions = $this->countTable('institutions');
        $beforeMemberships = $this->countTable('institution_memberships');
        $beforeStudents = $this->countTable('student_profiles');

        $client = $this->browser();
        $this->login($client, 'tloc-teacher@example.com');
        $client->request('GET', '/ogretmen/profil');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Seçilebilecek aktif okul yok.', (string) $client->getResponse()->getContent());

        $this->postProfile($client, $math, ['5', '9'], $home);
        self::assertResponseRedirects('/ogretmen/profil');
        $client->followRedirect();
        self::assertStringContainsString('Kayıtlı okul: tloc Il / tloc Ilce / tloc Okul', (string) $client->getResponse()->getContent());
        self::assertSame($this->snapshot($math, [5, 9], $home['school']), $this->profileOf('tloc-teacher@example.com'));

        $this->postProfile($client, $science, ['6'], [
            'province' => $home['province'],
            'district' => $home['district'],
            'school' => $home['school'],
        ]);
        $client->followRedirect();
        self::assertSame($this->snapshot('tloc-fen', [6], $home['school']), $this->profileOf('tloc-teacher@example.com'));
        self::assertSame($beforeRoles, $this->roles('tloc-teacher@example.com'));
        self::assertSame($beforeInstitutions, $this->countTable('institutions'));
        self::assertSame($beforeMemberships, $this->countTable('institution_memberships'));
        self::assertSame($beforeStudents, $this->countTable('student_profiles'));
    }

    public function testForeignInactiveAndWrongLevelSelectionsLeaveTheSavedProfileUntouched(): void
    {
        $math = $this->subject('tloc-mat', 'Matematik');
        $science = $this->subject('tloc-fen', 'Fen');
        $home = $this->chain('tloc Il', 'tloc Ilce', 'tloc Okul');
        $other = $this->chain('tloc Baska Il', 'tloc Baska Ilce', 'tloc Baska Okul');
        $this->createActive('tloc-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $client = $this->browser();
        $this->login($client, 'tloc-teacher@example.com');
        $this->postProfile($client, $math, ['5', '9'], $home);
        $client->followRedirect();
        $saved = $this->snapshot('tloc-mat', [5, 9], $home['school']);

        $this->postProfile($client, $science, ['6', '7'], [
            'province' => $other['province'],
            'district' => $home['district'],
            'school' => $home['school'],
        ]);
        $client->followRedirect();
        self::assertStringContainsString('Geçerli bir il, ilçe ve okul seçin.', (string) $client->getResponse()->getContent());
        self::assertSame($saved, $this->profileOf('tloc-teacher@example.com'));

        $this->postProfile($client, $science, ['8'], [
            'province' => $home['province'],
            'district' => $other['district'],
            'school' => $home['school'],
        ]);
        $client->followRedirect();
        self::assertSame($saved, $this->profileOf('tloc-teacher@example.com'));

        $this->deactivate($home['school']);
        $this->postProfile($client, $science, ['4'], $home);
        $client->followRedirect();
        self::assertSame($saved, $this->profileOf('tloc-teacher@example.com'));
        $this->activate($home['school']);

        $this->deactivate($home['district']);
        $this->postProfile($client, $science, ['3'], $home);
        $client->followRedirect();
        self::assertSame($saved, $this->profileOf('tloc-teacher@example.com'));
        $this->activate($home['district']);

        $this->deactivate($home['province']);
        $this->postProfile($client, $science, ['2'], $home);
        $client->followRedirect();
        self::assertSame($saved, $this->profileOf('tloc-teacher@example.com'));
        $this->activate($home['province']);

        $this->postProfile($client, $science, ['1'], [
            'province' => $home['province'],
            'district' => $home['district'],
            'school' => $home['district'],
        ]);
        $client->followRedirect();
        self::assertSame($saved, $this->profileOf('tloc-teacher@example.com'));
    }

    public function testEmptyCatalogStillSavesSubjectAndGrades(): void
    {
        $math = $this->subject('tloc-mat', 'Matematik');
        $this->createActive('tloc-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $client = $this->browser();
        $this->login($client, 'tloc-teacher@example.com');
        $client->request('GET', '/ogretmen/profil');
        self::assertStringContainsString(
            'Seçilebilecek aktif okul yok. Branş ve sınıf kaydı okul seçimi olmadan sürer.',
            (string) $client->getResponse()->getContent(),
        );

        $this->postProfile($client, $math, ['5'], null);
        self::assertResponseRedirects('/ogretmen/profil');
        $client->followRedirect();
        self::assertSame($this->snapshot('tloc-mat', [5], null), $this->profileOf('tloc-teacher@example.com'));
    }

    public function testAnotherTeacherCannotReplaceTheFirstSchool(): void
    {
        $math = $this->subject('tloc-mat', 'Matematik');
        $science = $this->subject('tloc-fen', 'Fen');
        $home = $this->chain('tloc Il', 'tloc Ilce', 'tloc Okul');
        $other = $this->chain('tloc Baska Il', 'tloc Baska Ilce', 'tloc Baska Okul');
        $this->createActive('tloc-a@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $this->createActive('tloc-b@example.com', UserRole::Teacher, 'Berk', 'Demir');
        $owner = $this->browser();
        $this->login($owner, 'tloc-a@example.com');
        $this->postProfile($owner, $math, ['4'], $home);
        $owner->followRedirect();
        $ownerId = $this->userId('tloc-a@example.com');

        $second = $this->browser();
        $this->login($second, 'tloc-b@example.com');
        $second->request('GET', '/ogretmen/profil');
        $token = $this->csrf($second, 'teacher_professional_profile');
        $second->request('POST', '/ogretmen/profil', [
            'teacher_profile' => [
                '_token' => $token,
                'subject' => $science,
                'gradeLevels' => ['8'],
                'provinceId' => $other['province'],
                'districtId' => $other['district'],
                'schoolId' => $other['school'],
                'user_id' => $ownerId,
            ],
        ]);
        self::assertResponseRedirects('/ogretmen/profil');
        self::assertSame($this->snapshot('tloc-mat', [4], $home['school']), $this->profileOf('tloc-a@example.com'));
        self::assertSame($this->snapshot('tloc-fen', [8], $other['school']), $this->profileOf('tloc-b@example.com'));
    }

    public function testUnauthorizedPostWithValidCsrfChangesNothing(): void
    {
        $math = $this->subject('tloc-mat', 'Matematik');
        $chain = $this->chain('tloc Il', 'tloc Ilce', 'tloc Okul');
        $this->createActive('tloc-applicant@example.com', UserRole::Student, 'Deniz', 'Kara');
        $this->withKernel(function (): void {
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            $applications->submit($this->user('tloc-applicant@example.com'));
        });
        $this->createActive('tloc-student@example.com', UserRole::Student, 'Ege', 'Ak');
        $this->createActive('tloc-parent@example.com', UserRole::Parent, 'Fatma', 'Ak');
        $this->createActive('tloc-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
        $this->withKernel(function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $this->user('tloc-admin@example.com')->setGlobalRoles([UserRole::Admin]);
            $em->flush();
        });
        $applicationBefore = $this->applicationStatus('tloc-applicant@example.com');
        $institutionsBefore = $this->countTable('institutions');
        $membershipsBefore = $this->countTable('institution_memberships');
        $studentsBefore = $this->countTable('student_profiles');

        foreach ([
            'tloc-applicant@example.com',
            'tloc-student@example.com',
            'tloc-parent@example.com',
            'tloc-admin@example.com',
        ] as $email) {
            $roles = $this->roles($email);
            $client = $this->browser();
            $this->login($client, $email);
            $client->request('GET', '/ogretmen/profil');
            self::assertResponseStatusCodeSame(403, $email);
            $token = $this->csrf($client, 'teacher_professional_profile');
            $client->request('POST', '/ogretmen/profil', [
                'teacher_profile' => [
                    '_token' => $token,
                    'subject' => $math,
                    'gradeLevels' => ['5'],
                    'provinceId' => $chain['province'],
                    'districtId' => $chain['district'],
                    'schoolId' => $chain['school'],
                ],
            ]);
            self::assertResponseStatusCodeSame(403, $email);
            self::assertNull($this->profileOf($email));
            self::assertSame($roles, $this->roles($email));
        }

        self::assertSame($applicationBefore, $this->applicationStatus('tloc-applicant@example.com'));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $applicationBefore);
        self::assertSame($institutionsBefore, $this->countTable('institutions'));
        self::assertSame($membershipsBefore, $this->countTable('institution_memberships'));
        self::assertSame($studentsBefore, $this->countTable('student_profiles'));
    }

    public function testLocationListUsesOneQueryAndHidesOtherLevels(): void
    {
        $province = $this->chain('tloc Il', 'tloc Ilce', 'tloc Okul');
        $this->location(LocationLevel::District, 'tloc Ilce Iki', $province['province']);
        $this->location(LocationLevel::District, 'tloc Ilce Uc', $province['province']);
        $this->classNode($province['district']);
        $this->createActive('tloc-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $this->createActive('tloc-student@example.com', UserRole::Student, 'Ege', 'Ak');

        $student = $this->browser();
        $this->login($student, 'tloc-student@example.com');
        $student->request('GET', '/ogretmen/profil/konumlar');
        self::assertResponseStatusCodeSame(403);

        $client = $this->browser();
        $this->login($client, 'tloc-teacher@example.com');

        self::assertSame(['tloc Ilce', 'tloc Ilce Iki', 'tloc Ilce Uc'], $this->locationNames($client, $province['province']));
        self::assertSame(1, $this->locationQueryCount($client));

        self::assertSame(['tloc Okul'], $this->locationNames($client, $province['district']));
        self::assertSame(1, $this->locationQueryCount($client));

        self::assertSame([], $this->locationNames($client, $province['school']));
        self::assertSame(1, $this->locationQueryCount($client));

        $client->request('GET', '/ogretmen/profil/konumlar?parent=not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array{province: string, district: string, school: string}|null $chain
     * @param list<string>                                                   $grades
     */
    private function postProfile(KernelBrowser $client, string $subjectId, array $grades, ?array $chain): void
    {
        $client->request('GET', '/ogretmen/profil');
        $token = $this->csrf($client, 'teacher_professional_profile');
        $payload = [
            '_token' => $token,
            'subject' => $subjectId,
            'gradeLevels' => $grades,
        ];
        if (null !== $chain) {
            $payload['provinceId'] = $chain['province'];
            $payload['districtId'] = $chain['district'];
            $payload['schoolId'] = $chain['school'];
        }
        $client->request('POST', '/ogretmen/profil', [
            'teacher_profile' => $payload,
        ]);
    }

    /**
     * @return array{province: string, district: string, school: string}
     */
    private function chain(string $province, string $district, string $school): array
    {
        $provinceId = $this->location(LocationLevel::Province, $province, null);
        $districtId = $this->location(LocationLevel::District, $district, $provinceId);
        $schoolId = $this->location(LocationLevel::School, $school, $districtId);

        return [
            'province' => $provinceId,
            'district' => $districtId,
            'school' => $schoolId,
        ];
    }

    private function location(LocationLevel $level, string $name, ?string $parentId): string
    {
        return $this->withKernel(static function () use ($level, $name, $parentId): string {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $parent = null;
            if (null !== $parentId) {
                $parent = $em->find(Location::class, Uuid::fromString($parentId));
                self::assertInstanceOf(Location::class, $parent);
            }
            $location = Location::create($level, $name, $parent, new \DateTimeImmutable('2026-10-11 12:00:00 UTC'));
            $em->persist($location);
            $em->flush();

            return $location->getId()->toRfc4122();
        });
    }

    private function classNode(string $districtId): void
    {
        $this->withKernel(static function () use ($districtId): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $inserted = $em->getConnection()->executeStatement(
                'INSERT INTO locations (id, parent_id, level, name, active, created_at, updated_at)
                 SELECT ?, id, ?, ?, 1, ?, ? FROM locations WHERE id = ?',
                [
                    Uuid::v7()->toBinary(),
                    'class',
                    'tloc Sinif',
                    '2026-10-11 12:00:00',
                    '2026-10-11 12:00:00',
                    Uuid::fromString($districtId)->toBinary(),
                ],
            );
            self::assertSame(1, $inserted);
        });
    }

    private function deactivate(string $id): void
    {
        $this->setActive($id, false);
    }

    private function activate(string $id): void
    {
        $this->setActive($id, true);
    }

    private function setActive(string $id, bool $active): void
    {
        $this->withKernel(static function () use ($id, $active): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $location = $em->find(Location::class, Uuid::fromString($id));
            self::assertInstanceOf(Location::class, $location);
            if ($active) {
                $em->getConnection()->executeStatement('UPDATE locations SET active = 1 WHERE id = ?', [$location->getId()->toBinary()]);
            } else {
                $location->deactivate();
                $em->flush();
            }
        });
    }

    private function subject(string $code, string $name): string
    {
        return $this->withKernel(static function () use ($code, $name): string {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $subject = Subject::create($code, $name, strtolower($name), $code, new \DateTimeImmutable('2026-10-11 12:00:00 UTC'));
            $em->persist($subject);
            $em->flush();

            return $subject->getId()->toRfc4122();
        });
    }

    private function createActive(string $email, UserRole $role, string $first, string $last): void
    {
        $this->withKernel(static function () use ($email, $role, $first, $last): void {
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
        });
    }

    /**
     * @param list<int> $grades
     *
     * @return array{subject: string, grades: list<int>, school: ?string}
     */
    private function snapshot(string $subjectCode, array $grades, ?string $schoolId): array
    {
        return [
            'subject' => $subjectCode,
            'grades' => $grades,
            'school' => $schoolId,
        ];
    }

    /**
     * @return array{subject: string, grades: list<int>, school: ?string}|null
     */
    private function profileOf(string $email): ?array
    {
        return $this->withKernel(function () use ($email): ?array {
            $profiles = static::getContainer()->get(TeacherProfessionalProfileRepository::class);
            self::assertInstanceOf(TeacherProfessionalProfileRepository::class, $profiles);
            $profile = $profiles->findOneByUser($this->user($email));
            if (null === $profile) {
                return null;
            }
            $grades = [];
            foreach ($profile->getGradeLevels() as $grade) {
                $grades[] = $grade->value;
            }

            return [
                'subject' => $profile->getSubject()->getCode(),
                'grades' => $grades,
                'school' => $profile->getSchoolLocation()?->getId()->toRfc4122(),
            ];
        });
    }

    /**
     * @return list<string>
     */
    private function locationNames(KernelBrowser $client, string $parentId): array
    {
        $holder = $client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $client->request('GET', '/ogretmen/profil/konumlar?parent='.$parentId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        return $this->decodeLocations($client);
    }

    /**
     * @return list<string>
     */
    private function decodeLocations(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $rows = $payload['locations'] ?? null;
        self::assertIsArray($rows);
        $names = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $names[] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    private function locationQueryCount(KernelBrowser $client): int
    {
        $holder = $client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $count = 0;
        foreach ($holder->getData() as $queries) {
            if (!\is_array($queries)) {
                continue;
            }
            foreach ($queries as $item) {
                $statement = \is_array($item) ? ($item['sql'] ?? null) : null;
                if (\is_string($statement) && str_contains($statement, 'locations')) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function roles(string $email): array
    {
        $roles = $this->withKernel(fn (): array => $this->user($email)->getRoles());

        return array_values(array_filter($roles, static fn (mixed $role): bool => \is_string($role)));
    }

    private function applicationStatus(string $email): string
    {
        return (string) $this->scalar(
            'SELECT a.status
             FROM teacher_applications a
             INNER JOIN users u ON u.id = a.user_id
             WHERE u.email = ?
             ORDER BY a.created_at DESC
             LIMIT 1',
            [$email],
        );
    }

    private function countTable(string $table): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM '.$table);
    }

    private function userId(string $email): string
    {
        return $this->withKernel(fn (): string => $this->user($email)->getId()->toRfc4122());
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
            $token = $tokens->getToken($intention)->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();

        return $token;
    }

    private function purge(): void
    {
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            $ids = $connection->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'tloc-%'");
            if ([] !== $ids) {
                $connection->executeStatement(
                    'DELETE FROM teacher_professional_profiles WHERE user_id IN (?)',
                    [$ids],
                    [ArrayParameterType::BINARY],
                );
                $connection->executeStatement(
                    'DELETE FROM teacher_applications WHERE user_id IN (?)',
                    [$ids],
                    [ArrayParameterType::BINARY],
                );
                $connection->executeStatement(
                    'DELETE FROM security_audit_events WHERE actor_user_id IN (?) OR subject_user_id IN (?)',
                    [$ids, $ids],
                    [ArrayParameterType::BINARY, ArrayParameterType::BINARY],
                );
                $connection->executeStatement('DELETE FROM users WHERE email LIKE ?', ['tloc-%']);
            }
            $connection->executeStatement("DELETE FROM subjects WHERE code LIKE 'tloc-%'");
            $connection->executeStatement(
                "DELETE p FROM teacher_professional_profiles p INNER JOIN locations l ON l.id = p.school_location_id WHERE l.name LIKE 'tloc %'",
            );
            $connection->executeStatement("DELETE FROM locations WHERE name LIKE 'tloc %' AND level NOT IN ('province', 'district', 'school')");
            $connection->executeStatement("DELETE FROM locations WHERE name LIKE 'tloc %' AND level = 'school'");
            $connection->executeStatement("DELETE FROM locations WHERE name LIKE 'tloc %' AND level = 'district'");
            $connection->executeStatement("DELETE FROM locations WHERE name LIKE 'tloc %' AND level = 'province'");
        });
    }
}
