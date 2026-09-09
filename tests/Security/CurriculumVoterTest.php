<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CurriculumProgram;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\CurriculumPermission;
use App\Security\RequestScopedInstitutionAuthLookup;
use App\Service\CurriculumProgramManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class CurriculumVoterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $access = $c->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
        $this->cleanup();
    }

    public function testPublishedViewAllowedForAnyActiveVerifiedUser(): void
    {
        [, $program] = $this->publishedProgram('cv_pub');
        $user = $this->activeUser('cv-pub-viewer@example.com');
        self::assertTrue($this->decide($user, CurriculumPermission::VIEW, $program));
    }

    public function testDraftViewDeniedForNonSuperAdmin(): void
    {
        [, $draft] = $this->draftProgram('cv_draft');
        $user = $this->activeUser('cv-draft-user@example.com');
        self::assertFalse($this->decide($user, CurriculumPermission::VIEW, $draft));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function privilegedAttributes(): iterable
    {
        yield 'manage' => [CurriculumPermission::MANAGE];
        yield 'publish' => [CurriculumPermission::PUBLISH];
        yield 'retire' => [CurriculumPermission::RETIRE];
    }

    #[DataProvider('privilegedAttributes')]
    public function testManagePublishRetireOnlySuperAdmin(string $attribute): void
    {
        $suffix = str_replace('CURRICULUM_', '', $attribute);
        [, $program] = $this->publishedProgram('cv_priv_'.strtolower($suffix));
        $user = $this->activeUser('cv-priv-'.$suffix.'@example.com');
        $sa = $this->superAdmin('cv-priv-sa-'.$suffix.'@example.com');

        self::assertFalse($this->decide($user, $attribute, $program));
        self::assertTrue($this->decide($sa, $attribute, $program));
    }

    public function testAdminAndModeratorDeniedManage(): void
    {
        [, $program] = $this->publishedProgram('cv_adm');
        $admin = $this->activeUser('cv-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $mod = $this->activeUser('cv-mod@example.com');
        $mod->addGlobalRole(UserRole::Moderator);
        $this->users->save($mod);

        self::assertFalse($this->decide($admin, CurriculumPermission::MANAGE, $program));
        self::assertFalse($this->decide($mod, CurriculumPermission::PUBLISH, $program));
        self::assertFalse($this->decide($admin, CurriculumPermission::VIEW, $this->draftProgram('cv_adm_d')[1]));
        self::assertFalse($this->decide($mod, CurriculumPermission::VIEW, $this->draftProgram('cv_adm_d2')[1]));
    }

    public function testPendingSuspendedUnverifiedDenied(): void
    {
        [, $program] = $this->publishedProgram('cv_gate');
        $user = $this->activeUser('cv-gate-user@example.com');

        foreach ([UserStatus::PendingVerification, UserStatus::Suspended] as $status) {
            $this->setUserStatusInDb($user, $status);
            $stale = $this->detachKeepingMemory($user);
            self::assertSame(UserStatus::Active, $stale->getStatus());
            $this->resetLookup();
            self::assertFalse($this->decide($stale, CurriculumPermission::VIEW, $program));
            $this->setUserStatusInDb($user, UserStatus::Active);
            $this->em->clear();
            $reloaded = $this->users->find($user->getId());
            self::assertInstanceOf(User::class, $reloaded);
            $user = $reloaded;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = :id',
            ['id' => $user->getId()->toBinary()],
        );
        $staleUnverified = $this->detachKeepingMemory($user);
        self::assertNotNull($staleUnverified->getEmailVerifiedAt());
        $this->resetLookup();
        self::assertFalse($this->decide($staleUnverified, CurriculumPermission::VIEW, $program));
    }

    public function testAnonymousDenied(): void
    {
        [, $program] = $this->publishedProgram('cv_anon');
        self::assertFalse($this->access->decide(new NullToken(), [CurriculumPermission::VIEW], $program));
    }

    public function testSuperAdminOverrideForDraft(): void
    {
        [, $draft] = $this->draftProgram('cv_sa_draft');
        $sa = $this->superAdmin('cv-sa-draft@example.com');
        self::assertTrue($this->decide($sa, CurriculumPermission::VIEW, $draft));
        self::assertTrue($this->decide($sa, CurriculumPermission::MANAGE, $draft));
        self::assertTrue($this->decide($sa, CurriculumPermission::PUBLISH, $draft));
    }

    public function testStaleSnapshotRequiresInvalidateBeforePublishedView(): void
    {
        [$sa, $draft] = $this->draftProgram('cv_stale');
        $viewer = $this->activeUser('cv-stale-viewer@example.com');

        self::assertFalse($this->decide($viewer, CurriculumPermission::VIEW, $draft));
        self::assertSame(CurriculumStatus::Draft, $draft->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE curriculum_programs SET status = :status, published_at = :publishedAt WHERE id = :id',
            [
                'status' => CurriculumStatus::Published->value,
                'publishedAt' => '2024-06-01 00:00:00',
                'id' => $draft->getId()->toBinary(),
            ],
        );
        self::assertSame(
            CurriculumStatus::Published->value,
            (string) $this->em->getConnection()->fetchOne(
                'SELECT status FROM curriculum_programs WHERE id = :id',
                ['id' => $draft->getId()->toBinary()],
            ),
        );
        self::assertSame(CurriculumStatus::Draft, $draft->getStatus(), 'managed entity still draft');

        self::assertFalse(
            $this->decide($viewer, CurriculumPermission::VIEW, $draft),
            'cached snapshot must still deny VIEW',
        );

        $lookup = static::getContainer()->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);
        $lookup->invalidateCurriculumProgram($draft->getId());

        self::assertTrue($this->decide($viewer, CurriculumPermission::VIEW, $draft));

        $freshDraft = $this->programManager()->createDraft(
            $draft->getSubject(),
            $sa,
            GradeLevel::Grade8,
            'cv_stale_pub',
            'Stale Pub',
            '1.0',
            'create_for_publish',
        );
        self::assertFalse($this->decide($viewer, CurriculumPermission::VIEW, $freshDraft));
        $this->programManager()->publish($freshDraft, $sa, 'publish');
        self::assertTrue($this->decide($viewer, CurriculumPermission::VIEW, $freshDraft));
    }

    /**
     * @return array{0: User, 1: CurriculumProgram}
     */
    private function publishedProgram(string $prefix): array
    {
        [$sa, $draft] = $this->draftProgram($prefix);
        $this->programManager()->publish($draft, $sa, 'publish');

        return [$sa, $draft];
    }

    /**
     * @return array{0: User, 1: CurriculumProgram}
     */
    private function draftProgram(string $prefix): array
    {
        $sa = $this->superAdmin($prefix.'-sa@example.com');
        $subject = $this->subjectManager()->create($sa, $prefix.'_subj', 'Subject '.$prefix, 'create_subj');
        $draft = $this->programManager()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade7,
            $prefix.'_prog',
            'Program '.$prefix,
            '1.0',
            'create_draft',
        );

        return [$sa, $draft];
    }

    private function decide(User $user, string $attribute, CurriculumProgram $program): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $program);
    }

    private function resetLookup(): void
    {
        $lookup = static::getContainer()->get(RequestScopedInstitutionAuthLookup::class);
        self::assertInstanceOf(RequestScopedInstitutionAuthLookup::class, $lookup);
        $lookup->reset();
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function detachKeepingMemory(object $entity): object
    {
        $this->em->detach($entity);

        return $entity;
    }

    private function setUserStatusInDb(User $user, UserStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = :status WHERE id = :id',
            [
                'status' => $status->value,
                'id' => $user->getId()->toBinary(),
            ],
        );
    }

    private function superAdmin(string $email): User
    {
        $user = $this->activeUser($email);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function subjectManager(): SubjectManager
    {
        $service = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $service);

        return $service;
    }

    private function programManager(): CurriculumProgramManager
    {
        $service = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $service);

        return $service;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'course_teacher_active_guards',
            'course_teacher_assignments',
            'classroom_course_active_guards',
            'classroom_courses',
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
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
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
