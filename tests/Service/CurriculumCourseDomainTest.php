<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Classroom;
use App\Entity\ClassroomCourse;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\ClassroomCourseFailureReason;
use App\Enum\ClassroomCourseStatus;
use App\Enum\CourseTeacherAssignmentFailureReason;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumFailureReason;
use App\Enum\CurriculumStatus;
use App\Enum\CurriculumTopicFailureReason;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\SubjectFailureReason;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\ClassroomCourseException;
use App\Exception\CourseTeacherAssignmentException;
use App\Exception\CurriculumException;
use App\Exception\CurriculumTopicException;
use App\Exception\CurriculumUnitException;
use App\Exception\InstitutionMembershipException;
use App\Exception\SubjectException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomCourseManager;
use App\Service\ClassroomManager;
use App\Service\CourseTeacherAssignmentManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CurriculumCourseDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionRepository $institutions;
    private InstitutionMembershipRepository $memberships;
    private SecurityAuditEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testSubjectCreateRenameArchiveAndImmutability(): void
    {
        $sa = $this->superAdmin('subj-sa@example.com');
        $subject = $this->subjectManager()->create($sa, 'mathematics', 'Mathematics', 'create_math');
        self::assertSame(SubjectStatus::Active, $subject->getStatus());
        self::assertSame('mathematics', $subject->getCode());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::SubjectCreated->value));

        $this->subjectManager()->rename($subject, $sa, 'Math Core', 'rename');
        self::assertSame('Math Core', $subject->getName());
        self::assertSame('mathematics', $subject->getCode());

        try {
            $this->subjectManager()->create($sa, 'Bad-Code!', 'Dup', 'dup_code');
            self::fail('code must be snake_case');
        } catch (SubjectException $e) {
            self::assertSame(SubjectFailureReason::InvalidInput, $e->getReason());
        }

        $this->subjectManager()->archive($subject, $sa, 'archive');
        self::assertSame(SubjectStatus::Archived, $subject->getStatus());

        try {
            $this->subjectManager()->rename($subject, $sa, 'Nope', 'bad');
            self::fail('archived rename blocked');
        } catch (SubjectException $e) {
            self::assertSame(SubjectFailureReason::SubjectArchived, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $owner = $this->activeUser('subj-owner@example.com');
        try {
            $this->subjectManager()->create($owner, 'physics', 'Physics', 'not_sa');
            self::fail('non SA blocked');
        } catch (SubjectException $e) {
            self::assertSame(SubjectFailureReason::Unauthorized, $e->getReason());
        }
        $this->resetDoctrine();
    }

    public function testCurriculumLifecyclePublishOverlapCloneRetire(): void
    {
        $sa = $this->superAdmin('cur-sa@example.com');
        $subject = $this->subjectManager()->create($sa, 'science', 'Science', 'create_sci');

        $draft = $this->programManager()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade9,
            'sci_9',
            'Science 9',
            '1.0',
            'create_draft',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-08-31'),
        );
        self::assertSame(CurriculumStatus::Draft, $draft->getStatus());

        $unit = $this->unitManager()->create($draft, $sa, 'u1', 'Unit 1', 1, 'unit_create', 60);
        $root = $this->topicManager()->createRoot($unit, $sa, 't1', 'Topic 1', 1, 'topic_root');
        $this->topicManager()->createChild($unit, $root, $sa, 't1_1', 'Child', 1, 'topic_child');

        $this->programManager()->publish($draft, $sa, 'publish');
        self::assertSame(CurriculumStatus::Published, $draft->getStatus());
        self::assertNotNull($draft->getPublishedAt());

        try {
            $this->unitManager()->create($draft, $sa, 'u2', 'Unit 2', 2, 'blocked');
            self::fail('published immutable');
        } catch (CurriculumUnitException) {
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $subject = $this->em->find(Subject::class, $subject->getId());
        self::assertInstanceOf(Subject::class, $subject);
        $draft = $this->em->find(CurriculumProgram::class, $draft->getId());
        self::assertInstanceOf(CurriculumProgram::class, $draft);

        $overlap = $this->programManager()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade9,
            'sci_9b',
            'Science 9B',
            '1.0',
            'overlap_draft',
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-12-31'),
        );
        try {
            $this->programManager()->publish($overlap, $sa, 'publish_overlap');
            self::fail('date overlap');
        } catch (CurriculumException $e) {
            self::assertSame(CurriculumFailureReason::DateOverlap, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $draft = $this->em->find(CurriculumProgram::class, $draft->getId());
        self::assertInstanceOf(CurriculumProgram::class, $draft);

        $clone = $this->programManager()->cloneAsNewVersion($draft, $sa, '2.0', 'clone');
        self::assertSame(CurriculumStatus::Draft, $clone->getStatus());
        self::assertSame('2.0', $clone->getVersion());
        self::assertCount(1, $this->em->getRepository(CurriculumUnit::class)->findBy(['program' => $clone]));

        $this->programManager()->retire($draft, $sa, 'retire');
        self::assertSame(CurriculumStatus::Retired, $draft->getStatus());
        try {
            $this->programManager()->publish($draft, $sa, 'repub');
            self::fail('no republish');
        } catch (CurriculumException $e) {
            self::assertSame(CurriculumFailureReason::InvalidTransition, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);

        $archivedSubject = $this->subjectManager()->create($sa, 'history', 'History', 'hist');
        $this->subjectManager()->archive($archivedSubject, $sa, 'arch_hist');
        try {
            $this->programManager()->createDraft(
                $archivedSubject,
                $sa,
                GradeLevel::Grade5,
                'hist_5',
                'History 5',
                '1.0',
                'blocked_subj',
            );
            self::fail('archived subject');
        } catch (CurriculumException $e) {
            self::assertSame(CurriculumFailureReason::SubjectArchived, $e->getReason());
        }
        $this->resetDoctrine();
    }

    public function testTopicDepthActiveChildrenAndRootPosition(): void
    {
        $sa = $this->superAdmin('topic-sa@example.com');
        $subject = $this->subjectManager()->create($sa, 'turkish', 'Turkish', 'tr');
        $program = $this->programManager()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade6,
            'tr_6',
            'Turkish 6',
            '1.0',
            'draft',
        );
        $unit = $this->unitManager()->create($program, $sa, 'u1', 'Unit', 1, 'create_unit');
        $root = $this->topicManager()->createRoot($unit, $sa, 'r1', 'Root', 1, 'create_root');
        $child = $this->topicManager()->createChild($unit, $root, $sa, 'c1', 'Child', 1, 'create_child');

        try {
            $this->topicManager()->createChild($unit, $child, $sa, 'gc1', 'Grand', 1, 'create_grand');
            self::fail('depth exceeded');
        } catch (CurriculumTopicException $e) {
            self::assertSame(CurriculumTopicFailureReason::DepthExceeded, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $unit = $this->em->find(CurriculumUnit::class, $unit->getId());
        $root = $this->em->find(CurriculumTopic::class, $root->getId());
        $child = $this->em->find(CurriculumTopic::class, $child->getId());
        self::assertNotNull($unit);
        self::assertNotNull($root);
        self::assertNotNull($child);

        try {
            $this->topicManager()->archive($root, $sa, 'arch_parent');
            self::fail('active children');
        } catch (CurriculumTopicException $e) {
            self::assertSame(CurriculumTopicFailureReason::ActiveChildren, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $unit = $this->em->find(CurriculumUnit::class, $unit->getId());
        $root = $this->em->find(CurriculumTopic::class, $root->getId());
        $child = $this->em->find(CurriculumTopic::class, $child->getId());
        self::assertNotNull($unit);
        self::assertNotNull($root);
        self::assertNotNull($child);

        $this->topicManager()->archive($child, $sa, 'arch_child');
        self::assertSame(CurriculumContentStatus::Archived, $child->getStatus());
        $this->topicManager()->archive($root, $sa, 'arch_root');

        try {
            $this->topicManager()->createRoot($unit, $sa, 'r2', 'Root2', 1, 'dup_pos');
            self::fail('root position conflict');
        } catch (CurriculumTopicException $e) {
            self::assertSame(CurriculumTopicFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();

        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $unit = $this->em->find(CurriculumUnit::class, $unit->getId());
        self::assertNotNull($unit);

        $rootB = $this->topicManager()->createRoot($unit, $sa, 'rb', 'RootB', 2, 'create_rb');
        $rootC = $this->topicManager()->createRoot($unit, $sa, 'rc', 'RootC', 3, 'create_rc');
        $reorderAuditsBefore = $this->events->countByAction(SecurityAuditAction::CurriculumTopicReordered->value);
        try {
            $this->topicManager()->reorder($rootC, $sa, 2, 'dup_reorder');
            self::fail('reorder root duplicate');
        } catch (CurriculumTopicException $e) {
            self::assertSame(CurriculumTopicFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();
        $rootC = $this->em->find(CurriculumTopic::class, $rootC->getId());
        self::assertNotNull($rootC);
        self::assertSame(3, $rootC->getPosition());
        self::assertSame(
            $reorderAuditsBefore,
            $this->events->countByAction(SecurityAuditAction::CurriculumTopicReordered->value),
            'rejected reorder must not write success audit',
        );

        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $unit = $this->em->find(CurriculumUnit::class, $unit->getId());
        $rootB = $this->em->find(CurriculumTopic::class, $rootB->getId());
        self::assertNotNull($unit);
        self::assertNotNull($rootB);
        $child1 = $this->topicManager()->createChild($unit, $rootB, $sa, 'cb1', 'CB1', 1, 'cb1');
        $child2 = $this->topicManager()->createChild($unit, $rootB, $sa, 'cb2', 'CB2', 2, 'cb2');
        $childReorderAuditsBefore = $this->events->countByAction(SecurityAuditAction::CurriculumTopicReordered->value);
        try {
            $this->topicManager()->reorder($child2, $sa, 1, 'dup_child_reorder');
            self::fail('reorder child duplicate');
        } catch (CurriculumTopicException $e) {
            self::assertSame(CurriculumTopicFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();
        $child2 = $this->em->find(CurriculumTopic::class, $child2->getId());
        self::assertNotNull($child2);
        self::assertSame(2, $child2->getPosition());
        self::assertSame(
            $childReorderAuditsBefore,
            $this->events->countByAction(SecurityAuditAction::CurriculumTopicReordered->value),
        );
        unset($child1);
    }

    public function testClassroomCourseCreateChangeArchiveAndGuards(): void
    {
        [$owner, $sa, $institution, $classroom, $subject, $program] = $this->readyCourseStack('cc');
        $course = $this->courseManager()->create($classroom, $owner, $subject, $program, 'create_course', 4);
        self::assertSame(ClassroomCourseStatus::Active, $course->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM classroom_course_active_guards'));

        try {
            $this->courseManager()->create($classroom, $owner, $subject, $program, 'dup');
            self::fail('active guard conflict');
        } catch (ClassroomCourseException $e) {
            self::assertSame(ClassroomCourseFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();
        $owner = $this->users->find($owner->getId());
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $owner);
        self::assertInstanceOf(User::class, $sa);
        $course = $this->em->find(ClassroomCourse::class, $course->getId());
        $classroom = $this->em->find(Classroom::class, $classroom->getId());
        $subject = $this->em->find(Subject::class, $subject->getId());
        $program = $this->em->find(CurriculumProgram::class, $program->getId());
        $institution = $this->institutions->find($institution->getId());
        self::assertNotNull($course);
        self::assertNotNull($classroom);
        self::assertNotNull($subject);
        self::assertNotNull($program);
        self::assertNotNull($institution);

        $this->courseManager()->changeWeeklyLessonHours($course, $owner, 6, 'hours');
        self::assertSame(6, $course->getWeeklyLessonHours());

        $v2 = $this->programManager()->cloneAsNewVersion($program, $sa, '2.0', 'clone_v2');
        $this->programManager()->retire($program, $sa, 'retire_v1');
        $this->programManager()->publish($v2, $sa, 'pub_v2');
        $oldProgramId = $program->getId()->toRfc4122();
        $this->courseManager()->changeCurriculum($course, $owner, $v2, 'change_cur');
        self::assertTrue($course->getCurriculumProgram()->getId()->equals($v2->getId()));
        $changedEvent = $this->em->getRepository(\App\Entity\SecurityAuditEvent::class)->findOneBy(
            ['action' => SecurityAuditAction::ClassroomCourseCurriculumChanged],
            ['occurredAt' => 'DESC'],
        );
        self::assertInstanceOf(\App\Entity\SecurityAuditEvent::class, $changedEvent);
        $meta = $changedEvent->getMetadata();
        self::assertSame($oldProgramId, $meta['old_curriculum_id'] ?? null);
        self::assertSame($v2->getId()->toRfc4122(), $meta['new_curriculum_id'] ?? null);
        self::assertSame($v2->getId()->toRfc4122(), $meta['curriculum_id'] ?? null);

        $teacher = $this->activeUser('cc-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_t');
        $tm = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $tm);
        $this->courseTeacherManager()->assign($course, $owner, $tm, 'assign');

        try {
            $this->courseManager()->archive($course, $owner, 'arch_blocked');
            self::fail('active teachers');
        } catch (ClassroomCourseException $e) {
            self::assertSame(ClassroomCourseFailureReason::ActiveTeachers, $e->getReason());
        }
        $this->resetDoctrine();
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $course = $this->em->find(ClassroomCourse::class, $course->getId());
        self::assertNotNull($course);
        $assignment = $this->em->getRepository(\App\Entity\CourseTeacherAssignment::class)->findOneBy([
            'classroomCourse' => $course,
            'status' => CourseTeacherAssignmentStatus::Active,
        ]);
        self::assertNotNull($assignment);
        $this->courseTeacherManager()->endAssignment($assignment, $owner, 'end');
        $this->courseManager()->archive($course, $owner, 'archive');
        self::assertSame(ClassroomCourseStatus::Archived, $course->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM classroom_course_active_guards'));
    }

    public function testCourseTeacherAssignEndNoReactivateAndMembershipBlock(): void
    {
        [$owner, , $institution, $classroom, $subject, $program] = $this->readyCourseStack('cta');
        $course = $this->courseManager()->create($classroom, $owner, $subject, $program, 'create');
        $teacher = $this->activeUser('cta-t@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add');
        $tm = $this->memberships->findActiveMembership($teacher, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $tm);

        $assignment = $this->courseTeacherManager()->assign($course, $owner, $tm, 'assign');
        self::assertSame(CourseTeacherAssignmentStatus::Active, $assignment->getStatus());

        try {
            $this->courseTeacherManager()->assign($course, $owner, $tm, 'dup');
            self::fail('duplicate active');
        } catch (CourseTeacherAssignmentException $e) {
            self::assertSame(CourseTeacherAssignmentFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $assignment = $this->em->find(\App\Entity\CourseTeacherAssignment::class, $assignment->getId());
        $tm = $this->memberships->find($tm->getId());
        $institution = $this->institutions->find($institution->getId());
        self::assertNotNull($assignment);
        self::assertNotNull($tm);
        self::assertNotNull($institution);

        try {
            $this->membershipManager()->suspend($tm, $owner, 'suspend');
            self::fail('active course teacher blocks suspend');
        } catch (InstitutionMembershipException) {
        }
        $this->resetDoctrine();
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $assignment = $this->em->find(\App\Entity\CourseTeacherAssignment::class, $assignment->getId());
        $course = $this->em->find(ClassroomCourse::class, $course->getId());
        $tm = $this->memberships->find($tm->getId());
        self::assertNotNull($assignment);
        self::assertNotNull($course);
        self::assertNotNull($tm);

        $this->courseTeacherManager()->endAssignment($assignment, $owner, 'end');
        self::assertSame(CourseTeacherAssignmentStatus::Ended, $assignment->getStatus());
        try {
            $assignment->end(new \DateTimeImmutable());
            self::fail('no reactivate path');
        } catch (CourseTeacherAssignmentException $e) {
            self::assertSame(CourseTeacherAssignmentFailureReason::InvalidTransition, $e->getReason());
        }

        $again = $this->courseTeacherManager()->assign($course, $owner, $tm, 'reassign');
        self::assertSame(CourseTeacherAssignmentStatus::Active, $again->getStatus());
        self::assertFalse($again->getId()->equals($assignment->getId()));
    }

    public function testGradeAndSubjectMismatchOnCourseCreate(): void
    {
        [$owner, $sa, , $classroom, $subject] = $this->readyCourseStack('mis');
        $other = $this->subjectManager()->create($sa, 'art', 'Art', 'art');
        $wrongSubjectProgram = $this->programManager()->createDraft(
            $other,
            $sa,
            GradeLevel::Grade9,
            'art_9',
            'Art 9',
            '1.0',
            'draft',
        );
        $this->programManager()->publish($wrongSubjectProgram, $sa, 'pub');
        try {
            $this->courseManager()->create($classroom, $owner, $subject, $wrongSubjectProgram, 'bad_subj');
            self::fail('subject mismatch');
        } catch (ClassroomCourseException $e) {
            self::assertSame(ClassroomCourseFailureReason::SubjectMismatch, $e->getReason());
        }
        $this->resetDoctrine();
        $owner = $this->users->find($owner->getId());
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $owner);
        self::assertInstanceOf(User::class, $sa);
        $classroom = $this->em->find(Classroom::class, $classroom->getId());
        $subject = $this->em->find(Subject::class, $subject->getId());
        self::assertNotNull($classroom);
        self::assertNotNull($subject);

        $wrongGrade = $this->programManager()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade5,
            'math_5',
            'Math 5',
            '1.0',
            'draft5',
        );
        $this->programManager()->publish($wrongGrade, $sa, 'pub5');
        try {
            $this->courseManager()->create($classroom, $owner, $subject, $wrongGrade, 'bad_grade');
            self::fail('grade mismatch');
        } catch (ClassroomCourseException $e) {
            self::assertSame(ClassroomCourseFailureReason::GradeMismatch, $e->getReason());
        }
    }

    /**
     * @return array{0: User, 1: User, 2: Institution, 3: Classroom, 4: Subject, 5: CurriculumProgram}
     */
    private function readyCourseStack(string $prefix): array
    {
        $sa = $this->superAdmin($prefix.'-sa@example.com');
        $owner = $this->activeUser($prefix.'-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, 'School '.$prefix, InstitutionType::School, 'create_inst');
        $this->statusManager()->activate($institution, $sa, 'activate');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            'Year '.$prefix,
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $this->yearManager()->activate($year, $owner, 'act_year');
        $classroom = $this->classroomManager()->create(
            $year,
            $owner,
            '9-A '.$prefix,
            GradeLevel::Grade9,
            'cls',
            'A',
            30,
        );
        $subject = $this->subjectManager()->create($sa, $prefix.'_math', 'Math '.$prefix, 'subj');
        $program = $this->programManager()->createDraft(
            $subject,
            $sa,
            GradeLevel::Grade9,
            $prefix.'_prog',
            'Prog '.$prefix,
            '1.0',
            'prog',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );
        $this->programManager()->publish($program, $sa, 'pub');

        return [$owner, $sa, $institution, $classroom, $subject, $program];
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

    private function creator(): InstitutionCreator
    {
        $s = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $s);

        return $s;
    }

    private function statusManager(): InstitutionStatusManager
    {
        $s = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $s);

        return $s;
    }

    private function membershipManager(): InstitutionMembershipManager
    {
        $s = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $s);

        return $s;
    }

    private function yearManager(): AcademicYearManager
    {
        $s = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $s);

        return $s;
    }

    private function classroomManager(): ClassroomManager
    {
        $s = static::getContainer()->get(ClassroomManager::class);
        self::assertInstanceOf(ClassroomManager::class, $s);

        return $s;
    }

    private function subjectManager(): SubjectManager
    {
        $s = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $s);

        return $s;
    }

    private function programManager(): CurriculumProgramManager
    {
        $s = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $s);

        return $s;
    }

    private function unitManager(): CurriculumUnitManager
    {
        $s = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $s);

        return $s;
    }

    private function topicManager(): CurriculumTopicManager
    {
        $s = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $s);

        return $s;
    }

    private function courseManager(): ClassroomCourseManager
    {
        $s = static::getContainer()->get(ClassroomCourseManager::class);
        self::assertInstanceOf(ClassroomCourseManager::class, $s);

        return $s;
    }

    private function courseTeacherManager(): CourseTeacherAssignmentManager
    {
        $s = static::getContainer()->get(CourseTeacherAssignmentManager::class);
        self::assertInstanceOf(CourseTeacherAssignmentManager::class, $s);

        return $s;
    }

    private function rebind(): void
    {
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
        $institutions = $c->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $institutions);
        $this->institutions = $institutions;
        $memberships = $c->get(InstitutionMembershipRepository::class);
        self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
        $this->memberships = $memberships;
        $events = $c->get(SecurityAuditEventRepository::class);
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
        foreach ([
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
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
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
