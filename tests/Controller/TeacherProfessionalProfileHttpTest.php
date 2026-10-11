<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Subject;
use App\Entity\TeacherProfessionalProfile;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\TeacherApplicationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class TeacherProfessionalProfileHttpTest extends WebTestCase
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

    public function testTeacherCreatesViewsAndUpdatesOwnProfileWithoutChangingAccountOrPrivileges(): void
    {
        $math = $this->subject('tprof-mat', 'Matematik');
        $science = $this->subject('tprof-fen', 'Fen');
        $this->createActive('tprof-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $this->withKernel(function (): void {
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $student = $this->user('tprof-teacher@example.com');
            $student->setGlobalRoles([UserRole::Student]);
            $em->flush();
            $applications->submit($this->user('tprof-teacher@example.com'));
            $this->user('tprof-teacher@example.com')->addGlobalRole(UserRole::Teacher);
            $em->flush();
        });

        $beforeIdentity = $this->identity('tprof-teacher@example.com');
        $beforeRoles = $this->roles('tprof-teacher@example.com');
        $beforeApplication = $this->applicationStatus('tprof-teacher@example.com');
        $beforeInstitutions = $this->countTable('institutions');
        $beforeMemberships = $this->countTable('institution_memberships');

        $client = $this->browser();
        $this->login($client, 'tprof-teacher@example.com');
        $client->request('GET', '/ogretmen/profil');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mesleki profil', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertNull($this->profileOf('tprof-teacher@example.com'));

        $this->postProfile($client, $math, ['5', '5', '9']);
        self::assertResponseRedirects('/ogretmen/profil');
        $client->followRedirect();
        self::assertStringContainsString('Mesleki profil kaydedildi.', (string) $client->getResponse()->getContent());
        self::assertSame(['subject' => 'tprof-mat', 'grades' => [5, 9]], $this->profileOf('tprof-teacher@example.com'));

        $client->request('GET', '/calisma-alani');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/ogretmen/profil', (string) $client->getResponse()->getContent());

        $this->postProfile($client, $science, ['6', '7']);
        $client->followRedirect();
        self::assertSame(['subject' => 'tprof-fen', 'grades' => [6, 7]], $this->profileOf('tprof-teacher@example.com'));
        self::assertSame($beforeIdentity, $this->identity('tprof-teacher@example.com'));
        self::assertSame($beforeRoles, $this->roles('tprof-teacher@example.com'));
        self::assertSame($beforeApplication, $this->applicationStatus('tprof-teacher@example.com'));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $beforeApplication);
        self::assertSame($beforeInstitutions, $this->countTable('institutions'));
        self::assertSame($beforeMemberships, $this->countTable('institution_memberships'));
        self::assertSame(1, $this->profileCount('tprof-teacher@example.com'));
    }

    public function testAnotherTeacherAndForeignUserIdCannotChangeTheFirstProfile(): void
    {
        $math = $this->subject('tprof-mat', 'Matematik');
        $science = $this->subject('tprof-fen', 'Fen');
        $this->createActive('tprof-a@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $this->createActive('tprof-b@example.com', UserRole::Teacher, 'Berk', 'Demir');
        $client = $this->browser();
        $this->login($client, 'tprof-a@example.com');
        $this->postProfile($client, $math, ['4']);
        $client->followRedirect();
        $owner = $this->userId('tprof-a@example.com');

        $other = $this->browser();
        $this->login($other, 'tprof-b@example.com');
        $other->request('GET', '/ogretmen/profil');
        $token = $this->csrf($other, 'teacher_professional_profile');
        $other->request('POST', '/ogretmen/profil', [
            'teacher_profile' => [
                '_token' => $token,
                'subject' => $science,
                'gradeLevels' => ['8'],
                'user_id' => $owner,
            ],
        ]);
        self::assertResponseRedirects('/ogretmen/profil');
        self::assertSame(['subject' => 'tprof-mat', 'grades' => [4]], $this->profileOf('tprof-a@example.com'));
        self::assertSame(['subject' => 'tprof-fen', 'grades' => [8]], $this->profileOf('tprof-b@example.com'));
    }

    public function testApplicantStudentParentAndAdminAreDenied(): void
    {
        $math = $this->subject('tprof-mat', 'Matematik');
        $this->createActive('tprof-applicant@example.com', UserRole::Student, 'Deniz', 'Kara');
        $this->withKernel(function (): void {
            $applications = static::getContainer()->get(TeacherApplicationManager::class);
            self::assertInstanceOf(TeacherApplicationManager::class, $applications);
            $applications->submit($this->user('tprof-applicant@example.com'));
        });
        $this->createActive('tprof-student@example.com', UserRole::Student, 'Ege', 'Ak');
        $this->createActive('tprof-parent@example.com', UserRole::Parent, 'Fatma', 'Ak');
        $this->createActive('tprof-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
        $this->withKernel(function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $this->user('tprof-admin@example.com')->setGlobalRoles([UserRole::Admin]);
            $em->flush();
        });

        $emails = [
            'tprof-applicant@example.com',
            'tprof-student@example.com',
            'tprof-parent@example.com',
            'tprof-admin@example.com',
        ];
        $rolesBefore = [];
        foreach ($emails as $email) {
            $rolesBefore[$email] = $this->roles($email);
        }
        $applicationBefore = $this->applicationStatus('tprof-applicant@example.com');
        $institutionsBefore = $this->countTable('institutions');
        $membershipsBefore = $this->countTable('institution_memberships');

        foreach ($emails as $email) {
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
                ],
            ]);
            self::assertResponseStatusCodeSame(403, $email);
            self::assertNull($this->profileOf($email));
            self::assertSame($rolesBefore[$email], $this->roles($email));
        }

        self::assertSame($applicationBefore, $this->applicationStatus('tprof-applicant@example.com'));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $applicationBefore);
        self::assertSame($institutionsBefore, $this->countTable('institutions'));
        self::assertSame($membershipsBefore, $this->countTable('institution_memberships'));

        $admin = $this->browser();
        $this->login($admin, 'tprof-admin@example.com');
        $admin->request('GET', '/calisma-alani');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Mesleki profil', (string) $admin->getResponse()->getContent());
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus('tprof-applicant@example.com'));
    }

    public function testSuspendedAndUnverifiedTeachersAreDenied(): void
    {
        $this->createActive('tprof-suspended@example.com', UserRole::Teacher, 'Su', 'Mert');
        $this->createActive('tprof-unverified@example.com', UserRole::Teacher, 'Naz', 'Mert');
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement(
                'UPDATE users SET email_verified_at = NULL WHERE email = ?',
                ['tprof-unverified@example.com'],
            );
        });

        $suspended = $this->browser();
        $this->login($suspended, 'tprof-suspended@example.com');
        $this->setStatus('tprof-suspended@example.com', UserStatus::Suspended);
        $suspended->request('GET', '/ogretmen/profil');
        self::assertResponseRedirects('/giris');
        self::assertNull($this->profileOf('tprof-suspended@example.com'));

        $unverified = $this->browser();
        $this->login($unverified, 'tprof-unverified@example.com');
        $unverified->request('GET', '/ogretmen/profil');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->profileOf('tprof-unverified@example.com'));
    }

    public function testExpertAndHeadTeachersCanSaveTheirOwnProfiles(): void
    {
        $math = $this->subject('tprof-mat', 'Matematik');
        $this->createActive('tprof-expert@example.com', UserRole::ExpertTeacher, 'Eda', 'Uslu');
        $this->createActive('tprof-head@example.com', UserRole::HeadTeacher, 'Hakan', 'Uslu');

        foreach (['tprof-expert@example.com', 'tprof-head@example.com'] as $email) {
            $rolesBefore = $this->roles($email);
            $client = $this->browser();
            $this->login($client, $email);
            $client->request('GET', '/ogretmen/profil');
            self::assertResponseIsSuccessful();
            $this->postProfile($client, $math, ['10']);
            self::assertResponseRedirects('/ogretmen/profil');
            self::assertSame(['subject' => 'tprof-mat', 'grades' => [10]], $this->profileOf($email));
            self::assertSame($rolesBefore, $this->roles($email));
            self::assertNotContains(UserRole::Teacher->value, $rolesBefore);
        }
    }

    public function testInvalidSubjectGradesAndCsrfWriteNothingAndGetWritesNothing(): void
    {
        $math = $this->subject('tprof-mat', 'Matematik');
        $archived = $this->subject('tprof-old', 'Eski');
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement(
                "UPDATE subjects SET status = 'archived' WHERE code = ?",
                ['tprof-old'],
            );
        });
        $this->createActive('tprof-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $client = $this->browser();
        $this->login($client, 'tprof-teacher@example.com');
        $client->request('GET', '/ogretmen/profil');
        self::assertResponseIsSuccessful();
        $token = $this->csrf($client, 'teacher_professional_profile');
        self::assertNull($this->profileOf('tprof-teacher@example.com'));
        foreach ([
            ['subject' => '00000000-0000-4000-8000-000000000099', 'gradeLevels' => ['5'], '_token' => $token],
            ['subject' => $archived, 'gradeLevels' => ['5'], '_token' => $token],
            ['subject' => $math, 'gradeLevels' => [], '_token' => $token],
            ['subject' => $math, 'gradeLevels' => ['13'], '_token' => $token],
            ['subject' => $math, 'gradeLevels' => ['5'], '_token' => 'bad-token'],
        ] as $payload) {
            $client->request('POST', '/ogretmen/profil', ['teacher_profile' => $payload]);
            self::assertNull($this->profileOf('tprof-teacher@example.com'));
        }

        $this->postProfile($client, $math, ['5', '9']);
        self::assertResponseRedirects('/ogretmen/profil');
        $client->followRedirect();
        $client->request('GET', '/ogretmen/profil');
        $savedToken = $this->csrf($client, 'teacher_professional_profile');
        $saved = $this->profileOf('tprof-teacher@example.com');
        self::assertSame(['subject' => 'tprof-mat', 'grades' => [5, 9]], $saved);
        foreach ([
            ['subject' => '00000000-0000-4000-8000-000000000099', 'gradeLevels' => ['6'], '_token' => $savedToken],
            ['subject' => $archived, 'gradeLevels' => ['6'], '_token' => $savedToken],
            ['subject' => $math, 'gradeLevels' => [], '_token' => $savedToken],
            ['subject' => $math, 'gradeLevels' => ['13'], '_token' => $savedToken],
            ['subject' => $math, 'gradeLevels' => ['6', '7'], '_token' => 'bad-token'],
        ] as $payload) {
            $client->request('POST', '/ogretmen/profil', ['teacher_profile' => $payload]);
            self::assertSame($saved, $this->profileOf('tprof-teacher@example.com'));
        }
    }

    public function testClosedCatalogExplainsThatSavingIsUnavailable(): void
    {
        $this->createActive('tprof-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $codes = $this->withKernel(static function (): array {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $codes = $em->getConnection()->fetchFirstColumn("SELECT code FROM subjects WHERE status = 'active'");
            $em->getConnection()->executeStatement("UPDATE subjects SET status = 'archived' WHERE status = 'active'");
            $activeCodes = [];
            foreach ($codes as $code) {
                if (\is_string($code) && '' !== $code) {
                    $activeCodes[] = $code;
                }
            }

            return $activeCodes;
        });

        try {
            $client = $this->browser();
            $this->login($client, 'tprof-teacher@example.com');
            $client->request('GET', '/ogretmen/profil');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Seçilebilecek aktif branş yok. Profil kaydı kapalı.', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('>Kaydet<', (string) $client->getResponse()->getContent());
            $client->request('POST', '/ogretmen/profil', [
                'teacher_profile' => [
                    '_token' => 'ignored',
                    'subject' => '00000000-0000-4000-8000-000000000099',
                    'gradeLevels' => ['5'],
                ],
            ]);
            self::assertResponseRedirects('/ogretmen/profil');
            self::assertNull($this->profileOf('tprof-teacher@example.com'));
        } finally {
            $this->withKernel(static function () use ($codes): void {
                if ([] === $codes) {
                    return;
                }
                $em = static::getContainer()->get(EntityManagerInterface::class);
                self::assertInstanceOf(EntityManagerInterface::class, $em);
                $em->getConnection()->executeStatement(
                    "UPDATE subjects SET status = 'active' WHERE code IN (?)",
                    [$codes],
                    [ArrayParameterType::STRING],
                );
            });
        }
    }

    public function testMappingKeepsOneProfilePerUser(): void
    {
        $this->createActive('tprof-teacher@example.com', UserRole::Teacher, 'Ayşe', 'Yılmaz');
        $this->subject('tprof-mat', 'Matematik');
        $this->withKernel(function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $meta = $em->getClassMetadata(TeacherProfessionalProfile::class);
            $userJoin = $meta->getAssociationMapping('user');
            $subjectJoin = $meta->getAssociationMapping('subject');
            self::assertInstanceOf(ToOneOwningSideMapping::class, $userJoin);
            self::assertInstanceOf(ToOneOwningSideMapping::class, $subjectJoin);
            self::assertSame('user_id', $userJoin->joinColumns[0]->name);
            self::assertFalse($userJoin->joinColumns[0]->nullable);
            self::assertSame('CASCADE', $userJoin->joinColumns[0]->onDelete);
            self::assertSame('subject_id', $subjectJoin->joinColumns[0]->name);
            self::assertFalse($subjectJoin->joinColumns[0]->nullable);
            self::assertSame('RESTRICT', $subjectJoin->joinColumns[0]->onDelete);
            $schoolJoin = $meta->getAssociationMapping('schoolLocation');
            self::assertInstanceOf(ToOneOwningSideMapping::class, $schoolJoin);
            self::assertSame('school_location_id', $schoolJoin->joinColumns[0]->name);
            self::assertTrue($schoolJoin->joinColumns[0]->nullable);
            self::assertSame('RESTRICT', $schoolJoin->joinColumns[0]->onDelete);
            $unique = $meta->table['uniqueConstraints']['uniq_teacher_professional_profiles_user'] ?? null;
            self::assertIsArray($unique);
            self::assertSame(['user_id'], $unique['columns']);

            $index = $em->getConnection()->fetchAssociative(
                'SELECT INDEX_NAME, NON_UNIQUE
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = \'teacher_professional_profiles\'
                   AND COLUMN_NAME = \'user_id\'
                   AND NON_UNIQUE = 0',
            );
            self::assertIsArray($index);
            self::assertSame('uniq_teacher_professional_profiles_user', $index['INDEX_NAME']);

            $user = $this->user('tprof-teacher@example.com');
            $subject = $em->getRepository(Subject::class)->findOneBy(['code' => 'tprof-mat']);
            self::assertInstanceOf(Subject::class, $subject);
            $now = new \DateTimeImmutable('2026-10-11 12:00:00 UTC');
            $em->persist(TeacherProfessionalProfile::create($user, $subject, [GradeLevel::Grade5], $now));
            $em->flush();
            $em->persist(TeacherProfessionalProfile::create($user, $subject, [GradeLevel::Grade6], $now));
            $rejected = false;
            try {
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                $rejected = true;
            }
            self::assertTrue($rejected);
        });

        self::assertSame(1, $this->profileCount('tprof-teacher@example.com'));
    }

    /**
     * @param list<string> $grades
     */
    private function postProfile(KernelBrowser $client, string $subjectId, array $grades): void
    {
        $client->request('GET', '/ogretmen/profil');
        $token = $this->csrf($client, 'teacher_professional_profile');
        $client->request('POST', '/ogretmen/profil', [
            'teacher_profile' => [
                '_token' => $token,
                'subject' => $subjectId,
                'gradeLevels' => $grades,
            ],
        ]);
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
     * @return array{subject: string, grades: list<int>}|null
     */
    private function profileOf(string $email): ?array
    {
        $row = $this->withKernel(static function () use ($email): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchAssociative(
                'SELECT s.code AS subject_code, p.grade_levels
                 FROM teacher_professional_profiles p
                 INNER JOIN users u ON u.id = p.user_id
                 INNER JOIN subjects s ON s.id = p.subject_id
                 WHERE u.email = ?',
                [$email],
            );
        });
        if (!\is_array($row)) {
            return null;
        }
        $grades = json_decode((string) $row['grade_levels'], true);

        $values = [];
        if (\is_array($grades)) {
            foreach ($grades as $value) {
                $values[] = (int) $value;
            }
        }

        return [
            'subject' => (string) $row['subject_code'],
            'grades' => $values,
        ];
    }

    private function profileCount(string $email): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*)
             FROM teacher_professional_profiles p
             INNER JOIN users u ON u.id = p.user_id
             WHERE u.email = ?',
            [$email],
        );
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, phone: string, password: string}
     */
    private function identity(string $email): array
    {
        $row = $this->withKernel(static function () use ($email): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchAssociative(
                'SELECT first_name, last_name, email, phone, password FROM users WHERE email = ?',
                [$email],
            );
        });
        self::assertIsArray($row);

        return [
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'email' => (string) $row['email'],
            'phone' => (string) ($row['phone'] ?? ''),
            'password' => (string) $row['password'],
        ];
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

    private function setStatus(string $email, UserStatus $status): void
    {
        $this->withKernel(static function () use ($email, $status): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement(
                'UPDATE users SET status = ? WHERE email = ?',
                [$status->value, $email],
            );
        });
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
            $ids = $connection->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'tprof-%'");
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
                $connection->executeStatement('DELETE FROM users WHERE email LIKE ?', ['tprof-%']);
            }
            $connection->executeStatement("DELETE FROM subjects WHERE code LIKE 'tprof-%'");
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
