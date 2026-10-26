<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CatalogUnit;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Exception\CurriculumDeletionException;
use App\Repository\CurriculumProgramRepository;
use App\Repository\UserRepository;
use App\Service\CatalogWriteService;
use App\Service\CurriculumDraftDeletion;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\StudentCatalogQuery;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminCurriculumManagementTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        QuestionBankDbCleanup::deleteTables($em->getConnection(), [
            'catalog_topic_lessons',
            'catalog_topic_assessments',
            'catalog_topics',
            'catalog_units',
            'catalog_subjects',
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'security_audit_events',
            'users',
        ]);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();
    }

    public function testUnusedDraftDeletesAndDependentDraftDoesNot(): void
    {
        $sa = $this->superAdmin('curriculum-delete-sa@example.com');
        $subject = $this->subjects()->create($sa, 'cur_del_subj', 'Silme dersi', 'curriculum_setup');
        $program = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade5, 'cur_del_prog', 'Silme programi', '1.0', 'curriculum_setup');
        $audits = $this->auditCount(SecurityAuditAction::CurriculumDraftDeleted);
        $this->deletion()->deleteProgram($program, $sa, 'curriculum_delete');
        self::assertNull($this->em->find(CurriculumProgram::class, $program->getId()));
        self::assertSame($audits + 1, $this->auditCount(SecurityAuditAction::CurriculumDraftDeleted));

        $program = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade5, 'cur_del_prog', 'Silme programi', '1.0', 'curriculum_setup');
        $reused = $program->getId();
        $unit = $this->units()->create($program, $sa, 'cur_del_u', 'Unite', 1, 'curriculum_setup');
        $before = $this->auditCount(SecurityAuditAction::CurriculumDraftDeleted);
        try {
            $this->deletion()->deleteProgram($program, $sa, 'curriculum_delete');
            self::fail('Dependent program was deleted.');
        } catch (CurriculumDeletionException $e) {
            self::assertSame('Alt kayıt olduğu için silinmedi.', $e->getMessage());
        }
        $fresh = $this->em->find(CurriculumProgram::class, $reused);
        self::assertInstanceOf(CurriculumProgram::class, $fresh);
        self::assertSame($before, $this->auditCount(SecurityAuditAction::CurriculumDraftDeleted));
        self::assertSame(CurriculumContentStatus::Active, $unit->getStatus());

        $oldUnitId = $unit->getId();
        $this->deletion()->deleteUnit($unit, $sa, 'curriculum_delete');
        $replacement = $this->units()->create($program, $sa, 'cur_del_u', 'Unite', 1, 'curriculum_setup');
        self::assertFalse($replacement->getId()->equals($oldUnitId));
    }

    public function testPublishingParentDoesNotChangeChildStatusAndStudentChainStaysClosed(): void
    {
        $sa = $this->superAdmin('curriculum-access-sa@example.com');
        $subject = $this->subjects()->create($sa, 'cur_acc_subj', 'Erisim dersi', 'curriculum_setup');
        $program = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade6, 'cur_acc_prog', 'Erisim programi', '1.0', 'curriculum_setup');
        $unit = $this->units()->create($program, $sa, 'cur_acc_u', 'Unite', 1, 'curriculum_setup');
        $topic = $this->topics()->createRoot($unit, $sa, 'cur_acc_t', 'Konu', 1, 'curriculum_setup');
        $outcome = $this->outcomes()->create($topic, $sa, 'cur_acc_o', 'Kazanim', 1, 'curriculum_setup');
        $this->programs()->publish($program, $sa, 'curriculum_publish');
        $this->em->clear();
        $freshOutcome = $this->em->find(CurriculumLearningOutcome::class, $outcome->getId());
        self::assertInstanceOf(CurriculumLearningOutcome::class, $freshOutcome);
        self::assertSame(CurriculumContentStatus::Active, $freshOutcome->getStatus());
        self::assertSame(CurriculumStatus::Published, $this->em->find(CurriculumProgram::class, $program->getId())?->getStatus());

        $catalog = $this->catalog();
        $catalogSubject = $catalog->createSubject(GradeLevel::Grade6, 'Erisim katalog', null, 1, 'erisim-katalog');
        $catalogUnit = $catalog->createUnit($catalogSubject->getId(), 'Erisim unite', null, 1, 'erisim-unite');
        $catalog->publishUnit($catalogUnit->getId());
        $query = static::getContainer()->get(StudentCatalogQuery::class);
        self::assertInstanceOf(StudentCatalogQuery::class, $query);
        self::assertNull($query->listPublishedUnitsForGradeSubject(GradeLevel::Grade6, 'erisim-katalog'));
        $catalog->publishSubject($catalogSubject->getId());
        $freshUnit = $this->em->find(CatalogUnit::class, $catalogUnit->getId());
        self::assertInstanceOf(CatalogUnit::class, $freshUnit);
        self::assertSame('published', $freshUnit->getStatus()->value);
    }

    public function testPublishedCatalogSubjectOpensTheExistingPublishedUnit(): void
    {
        $catalog = $this->catalog();
        $catalogSubject = $catalog->createSubject(GradeLevel::Grade6, 'Acik katalog', null, 1, 'acik-katalog');
        $catalogUnit = $catalog->createUnit($catalogSubject->getId(), 'Acik unite', null, 1, 'acik-unite');
        $catalog->publishUnit($catalogUnit->getId());
        $catalog->publishSubject($catalogSubject->getId());
        $query = static::getContainer()->get(StudentCatalogQuery::class);
        self::assertInstanceOf(StudentCatalogQuery::class, $query);
        $listed = $query->listPublishedUnitsForGradeSubject(GradeLevel::Grade6, 'acik-katalog');
        self::assertNotNull($listed);
        self::assertCount(1, $listed);
        $freshUnit = $this->em->find(CatalogUnit::class, $catalogUnit->getId());
        self::assertInstanceOf(CatalogUnit::class, $freshUnit);
        self::assertSame('published', $freshUnit->getStatus()->value);
    }

    public function testModeratorPublishDoesNotFollowManageAndFailureWritesNoSuccessAudit(): void
    {
        $sa = $this->superAdmin('curriculum-mod-sa@example.com');
        $moderator = $this->account('curriculum-mod-user@example.com', UserRole::Moderator);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($moderator);
        $subject = $this->subjects()->create($sa, 'cur_mod_subj', 'Moderator dersi', 'curriculum_setup');
        $program = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade4, 'cur_mod_prog', 'Moderator programi', '1.0', 'curriculum_setup');
        $before = $this->auditCount(SecurityAuditAction::CurriculumPublished);
        try {
            $this->programs()->publish($program, $moderator, 'curriculum_publish');
            self::fail('Moderator without publish grant published a program.');
        } catch (\App\Exception\CurriculumException $e) {
            self::assertSame('unauthorized', $e->getReason()->value);
        }
        self::assertSame(CurriculumStatus::Draft, $this->em->find(CurriculumProgram::class, $program->getId())?->getStatus());
        self::assertSame($before, $this->auditCount(SecurityAuditAction::CurriculumPublished));
    }

    public function testRenameKeepsCodeAndPublishedProgramRejectsTheChange(): void
    {
        $sa = $this->superAdmin('curriculum-rename-sa@example.com');
        $subject = $this->subjects()->create($sa, 'cur_ren_subj', 'Ad dersi', 'curriculum_setup');
        $program = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade3, 'cur_ren_prog', 'Eski ad', '1.0', 'curriculum_setup');
        $this->programs()->renameDraft($program, $sa, 'Yeni ad', 'curriculum_correction');
        $this->em->clear();
        $renamed = $this->em->find(CurriculumProgram::class, $program->getId());
        self::assertInstanceOf(CurriculumProgram::class, $renamed);
        self::assertSame('Yeni ad', $renamed->getName());
        self::assertSame('cur_ren_prog', $renamed->getCode());
        self::assertSame('1.0', $renamed->getVersion());
        $this->programs()->publish($renamed, $sa, 'curriculum_publish');
        $before = $this->auditCount(SecurityAuditAction::CurriculumProgramUpdated);
        try {
            $this->programs()->renameDraft($renamed, $sa, 'Sessiz ad', 'curriculum_correction');
            self::fail('Published program was renamed in place.');
        } catch (\App\Exception\CurriculumException $e) {
            self::assertSame('program_not_draft', $e->getReason()->value);
        }
        $this->em->clear();
        $fresh = $this->em->find(CurriculumProgram::class, $program->getId());
        self::assertInstanceOf(CurriculumProgram::class, $fresh);
        self::assertSame('Yeni ad', $fresh->getName());
        self::assertSame($before, $this->auditCount(SecurityAuditAction::CurriculumProgramUpdated));
    }

    public function testProgramSearchPageDoesNotGrowWithRowCount(): void
    {
        $sa = $this->superAdmin('curriculum-page-sa@example.com');
        $subject = $this->subjects()->create($sa, 'cur_page_subj', 'Sayfa dersi', 'curriculum_setup');
        for ($i = 1; $i <= 3; ++$i) {
            $this->programs()->createDraft($subject, $sa, GradeLevel::Grade1, 'cur_page_'.$i, 'Sayfa '.$i, '1.0', 'curriculum_setup');
        }
        $repository = static::getContainer()->get(CurriculumProgramRepository::class);
        self::assertInstanceOf(CurriculumProgramRepository::class, $repository);
        $page = $repository->searchAdmin('', null, null, $subject->getId(), 1, 2);
        self::assertSame(3, $page['total']);
        self::assertCount(2, $page['rows']);
    }

    private function auditCount(SecurityAuditAction $action): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = :action',
            ['action' => $action->value],
        );
    }

    private function superAdmin(string $email): User
    {
        return $this->account($email, UserRole::SuperAdmin);
    }

    private function account(string $email, UserRole $role): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ad', 'Soyad', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(\App\Enum\UserStatus::Active);
        $user->setGlobalRoles([$role]);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function subjects(): SubjectManager
    {
        $service = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $service);

        return $service;
    }

    private function programs(): CurriculumProgramManager
    {
        $service = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $service);

        return $service;
    }

    private function units(): CurriculumUnitManager
    {
        $service = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $service);

        return $service;
    }

    private function topics(): CurriculumTopicManager
    {
        $service = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $service);

        return $service;
    }

    private function outcomes(): CurriculumLearningOutcomeManager
    {
        $service = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $service);

        return $service;
    }

    private function deletion(): CurriculumDraftDeletion
    {
        $service = static::getContainer()->get(CurriculumDraftDeletion::class);
        self::assertInstanceOf(CurriculumDraftDeletion::class, $service);

        return $service;
    }

    private function catalog(): CatalogWriteService
    {
        $service = static::getContainer()->get(CatalogWriteService::class);
        self::assertInstanceOf(CatalogWriteService::class, $service);

        return $service;
    }
}
