<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Institution;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\LearningContentFailureReason;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\LearningContentType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\LearningContentException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\LearningContentRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class LearningContentDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private SecurityAuditEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testLifecycleReviewSeparationCloneAndTenantIsolation(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('lc_life');

        $manager = $this->contents();
        $content = $manager->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::TopicExplanation,
            'lc_motion',
            'Motion Explained',
            'Summary',
            LearningContentDocument::paragraph('Body text'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_lc',
            estimatedMinutes: 15,
        );
        self::assertSame(LearningContentStatus::Draft, $content->getStatus());
        self::assertSame(1, $content->getCurrentRevisionNumber());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::LearningContentCreated->value));

        $revisionRepo = static::getContainer()->get(LearningContentRevisionRepository::class);
        self::assertInstanceOf(LearningContentRevisionRepository::class, $revisionRepo);
        $rev1 = $revisionRepo->findOneByContentAndNumber($content, 1);
        self::assertInstanceOf(LearningContentRevision::class, $rev1);
        self::assertFalse($rev1->isSealed());

        $manager->updateUnsealedRevision(
            $rev1,
            $sa,
            LearningContentDocument::paragraph('Updated body'),
            'update_lc',
            estimatedMinutes: 20,
        );
        $this->em->refresh($rev1);
        self::assertFalse($rev1->isSealed());
        self::assertSame(20, $rev1->getEstimatedMinutes());

        $manager->submitForReview($content, $sa, 'submit_lc');
        $this->em->refresh($content);
        $this->em->refresh($rev1);
        self::assertSame(LearningContentStatus::InReview, $content->getStatus());
        self::assertTrue($rev1->isSealed());

        try {
            $manager->publish($content, $sa, 'self_publish');
            self::fail('review separation');
        } catch (LearningContentException $e) {
            self::assertSame(LearningContentFailureReason::ReviewSeparation, $e->getReason());
        }
        $this->resetDoctrine();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);

        $manager = $this->contents();
        $manager->publish($content, $reviewer, 'publish_lc');
        $this->em->refresh($content);
        self::assertSame(LearningContentStatus::Published, $content->getStatus());
        self::assertSame(1, $content->getPublishedRevisionNumber());
        self::assertNotNull($content->getPublishedAt());

        $clone = $manager->cloneAsNewRevision($content, $sa, 'clone_lc');
        $this->em->refresh($content);
        self::assertSame(2, $content->getCurrentRevisionNumber());
        self::assertSame(LearningContentStatus::Draft, $content->getStatus());
        self::assertFalse($clone->isSealed());
        self::assertSame(2, $clone->getRevisionNumber());

        $owner = $this->activeUser('lc-owner@example.com', UserRole::InstitutionManager);
        $institution = $this->institutions()->create(
            $sa,
            $owner,
            'LC School',
            InstitutionType::School,
            'create_inst',
        );
        $statusManager = static::getContainer()->get(\App\Service\InstitutionStatusManager::class);
        self::assertInstanceOf(\App\Service\InstitutionStatusManager::class, $statusManager);
        $statusManager->activate($institution, $sa, 'act_inst');

        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);
        $teacher = $this->activeUser('lc-teacher@example.com', UserRole::Teacher);
        $memberships->addMember($institution, $sa, $teacher, InstitutionMembershipRole::Teacher, 'add_teacher');

        $subject = $this->em->find(Subject::class, $subject->getId());
        self::assertInstanceOf(Subject::class, $subject);
        $lo = $this->em->find(CurriculumLearningOutcome::class, $lo->getId());
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        $manager = $this->contents();
        $instContent = $manager->createDraft(
            $teacher,
            LearningContentScope::Institution,
            $institution,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Worksheet,
            'lc_inst_ws',
            'Institution Worksheet',
            null,
            LearningContentDocument::paragraph('Tenant body'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_inst_lc',
        );
        self::assertSame(LearningContentScope::Institution, $instContent->getScope());
        self::assertInstanceOf(Institution::class, $instContent->getInstitution());

        $outsider = $this->activeUser('lc-outsider@example.com', UserRole::Teacher);
        try {
            $manager->createDraft(
                $outsider,
                LearningContentScope::Institution,
                $institution,
                $subject,
                GradeLevel::Grade9,
                LearningContentType::Document,
                'lc_blocked',
                'Blocked',
                null,
                LearningContentDocument::paragraph('Nope'),
                [['learningOutcome' => $lo, 'isPrimary' => true]],
                'blocked',
            );
            self::fail('tenant isolation');
        } catch (LearningContentException $e) {
            self::assertSame(LearningContentFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testSerializerIgnoresStorageKeyOnMedia(): void
    {
        [$sa] = $this->platformCurriculum('lc_ser');
        $media = static::getContainer()->get(\App\Service\StoredMediaAssetManager::class);
        self::assertInstanceOf(\App\Service\StoredMediaAssetManager::class, $media);
        $asset = $media->registerMetadata(
            $sa,
            \App\Enum\StoredMediaAssetScope::Platform,
            null,
            \App\Enum\StoredMediaAssetKind::Image,
            \App\Enum\StoredMediaStorageProvider::Local,
            'cover.png',
            'image/png',
            2048,
            str_repeat('11', 32),
            'reg_media',
        );

        $serializer = static::getContainer()->get(SerializerInterface::class);
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $json = $serializer->serialize($asset, 'json');
        self::assertStringNotContainsString('storageKey', $json);
        self::assertStringNotContainsString($asset->getStorageKey(), $json);
        self::assertStringContainsString('cover.png', $json);
    }

    /**
     * @return array{0: User, 1: User, 2: Subject, 3: CurriculumProgram, 4: CurriculumLearningOutcome}
     */
    private function platformCurriculum(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $reviewer = $this->activeUser($suffix.'-rev@example.com', UserRole::HeadTeacher);
        $subject = $this->subjects()->create($sa, $suffix.'_sub', 'LC Subject '.$suffix, 'create_s');
        $program = $this->programs()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade9,
            $suffix.'_prog',
            'Program '.$suffix,
            '1.0',
            'create_p',
        );
        $unit = $this->units()->create($program, $sa, 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_'.$suffix, 'Outcome', 1, 'create_lo');
        $this->programs()->publish($program, $sa, 'publish_p');

        return [$sa, $reviewer, $subject, $program, $lo];
    }

    private function superAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email, UserRole $role = UserRole::Student): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $role);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function contents(): LearningContentManager
    {
        $s = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $s);

        return $s;
    }

    private function subjects(): SubjectManager
    {
        $s = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $s);

        return $s;
    }

    private function programs(): CurriculumProgramManager
    {
        $s = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $s);

        return $s;
    }

    private function units(): CurriculumUnitManager
    {
        $s = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $s);

        return $s;
    }

    private function topics(): CurriculumTopicManager
    {
        $s = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $s);

        return $s;
    }

    private function outcomes(): CurriculumLearningOutcomeManager
    {
        $s = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $s);

        return $s;
    }

    private function institutions(): InstitutionCreator
    {
        $s = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $s);

        return $s;
    }

    private function rebind(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;
    }

    private function resetDoctrine(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
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
