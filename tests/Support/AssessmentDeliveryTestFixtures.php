<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Assessment;
use App\Entity\AssessmentPublication;
use App\Entity\Classroom;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResultReleasePolicy;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\AssessmentDeliveryAccessGate;
use App\Service\AssessmentDeliveryManager;
use App\Service\AssessmentManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared bootstrap helpers for Stage 2.10 assessment delivery tests.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait AssessmentDeliveryTestFixtures
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private SecurityAuditEventRepository $events;

    private function rebindDeliveryFixtures(): void
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
        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;
    }

    private function cleanupDeliveryFixtures(): void
    {
        $connection = $this->em->getConnection();
        AssessmentDbCleanup::deleteAssessments($connection);
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'course_teacher_active_guards',
            'course_teacher_assignments',
            'classroom_course_active_guards',
            'classroom_courses',
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
            'users',
        ]);
    }

    private function resetDoctrineDelivery(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindDeliveryFixtures();
    }

    private function activeUser(string $email, UserRole $role = UserRole::Student): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $role);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function superAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    /**
     * @return array{0: User, 1: User, 2: Institution, 3: \App\Entity\AcademicYear, 4: Classroom}
     */
    private function readyClassroom(string $prefix): array
    {
        $sa = $this->superAdmin($prefix.'-sa@example.com');
        $owner = $this->activeUser($prefix.'-owner@example.com');
        $institution = $this->institutionCreator()->create(
            $sa,
            $owner,
            $prefix.' School',
            InstitutionType::School,
            'platform_setup',
        );
        $this->institutionStatus()->activate($institution, $sa, 'activate');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            $prefix.' Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $this->yearManager()->activate($year, $owner, 'act');
        $classroom = $this->classroomManager()->create(
            $year,
            $owner,
            $prefix.' 9-A',
            GradeLevel::Grade9,
            'cls',
            'A',
            40,
        );

        return [$owner, $sa, $institution, $year, $classroom];
    }

    /**
     * @return array{
     *     owner: User,
     *     sa: User,
     *     reviewer: User,
     *     institution: Institution,
     *     classroom: Classroom,
     *     teacher: User,
     *     teacherMembership: InstitutionMembership,
     *     student: User,
     *     studentMembership: InstitutionMembership,
     *     assessment: Assessment,
     *     publication: AssessmentPublication
     * }
     */
    private function publishedDeliveryContext(string $prefix): array
    {
        [$owner, $sa, $institution, $year, $classroom] = $this->readyClassroom($prefix);
        unset($year);

        $reviewer = $this->activeUser($prefix.'-rev@example.com', UserRole::HeadTeacher);
        $teacher = $this->activeUser($prefix.'-teacher@example.com');
        $student = $this->activeUser($prefix.'-student@example.com');
        $teacherMembership = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $teacher,
            InstitutionMembershipRole::Teacher,
            'add_teacher',
        );
        $studentMembership = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $student,
            InstitutionMembershipRole::Student,
            'add_student',
        );
        $this->teacherManager()->assign(
            $classroom,
            $owner,
            $teacherMembership,
            TeacherAssignmentRole::HomeroomTeacher,
            'assign_t',
        );
        $this->enrollmentManager()->enroll($classroom, $owner, $studentMembership, 'enroll_s');

        [$assessment, $publication] = $this->publishPlatformAssessment($sa, $reviewer, $prefix);

        return [
            'owner' => $owner,
            'sa' => $sa,
            'reviewer' => $reviewer,
            'institution' => $institution,
            'classroom' => $classroom,
            'teacher' => $teacher,
            'teacherMembership' => $teacherMembership,
            'student' => $student,
            'studentMembership' => $studentMembership,
            'assessment' => $assessment,
            'publication' => $publication,
        ];
    }

    /**
     * @return array{0: Assessment, 1: AssessmentPublication}
     */
    private function publishPlatformAssessment(User $sa, User $reviewer, string $suffix): array
    {
        $subject = $this->subjects()->create($sa, 'math_'.$suffix, 'Math '.$suffix, 'create_subj');
        $draft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, 'math_'.$suffix, 'Math', '1.0', 'prog');
        $unit = $this->units()->create($draft, $sa, 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_'.$suffix, 'Outcome', 1, 'create_lo');
        $this->programs()->publish($draft, $sa, 'pub_curr');

        $question = $this->createPublishedPlatformQuestion($sa, $reviewer, $subject, $lo, $suffix);
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qRevision = $qRevisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $qRevision);

        $assessment = $this->assessments()->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            'Delivery Blueprint '.$suffix,
            null,
            null,
            3600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [$this->sectionWithItem($question, $qRevision)],
            'create_a',
        );
        $this->assessments()->submitForReview($assessment, $sa, 'submit_a');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        $this->assessments()->publish($assessment, $reviewer, 'publish_a');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        /** @var AssessmentPublicationRepository $pubs */
        $pubs = static::getContainer()->get(AssessmentPublicationRepository::class);
        $publication = $pubs->findOneBy(['assessment' => $assessment, 'publicationNumber' => 1]);
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        return [$assessment, $publication];
    }

    private function createPublishedPlatformQuestion(
        User $author,
        User $publisher,
        Subject $subject,
        CurriculumLearningOutcome $lo,
        string $suffix,
    ): Question {
        $question = $this->questions()->createDraftQuestion(
            $author,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Q '.$suffix.'?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.$suffix,
        );
        $this->questions()->submitForReview($question, $author, 'submit_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);
        $publisher = $this->users->find($publisher->getId());
        self::assertInstanceOf(User::class, $publisher);
        $this->questions()->publish($question, $publisher, 'pub_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);

        return $question;
    }

    /**
     * @return array{
     *     title: string,
     *     position: int,
     *     questionOrderMode: QuestionOrderMode,
     *     items: list<array{
     *         questionId: \Symfony\Component\Uid\Uuid,
     *         questionRevisionId: \Symfony\Component\Uid\Uuid,
     *         position: int,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool
     *     }>
     * }
     */
    private function sectionWithItem(Question $question, QuestionRevision $revision): array
    {
        return [
            'title' => 'Section 1',
            'position' => 1,
            'questionOrderMode' => QuestionOrderMode::Fixed,
            'items' => [[
                'questionId' => $question->getId(),
                'questionRevisionId' => $revision->getId(),
                'position' => 1,
                'points' => '2.50',
                'penaltyPoints' => '0.50',
                'required' => true,
            ]],
        ];
    }

    /**
     * Multi-section / multi-item platform assessment with shuffle so attempt presentation_position
     * can diverge from immutable blueprint section/item positions.
     *
     * @return array{
     *     assessment: Assessment,
     *     publication: AssessmentPublication,
     *     questions: list<Question>,
     *     revisions: list<QuestionRevision>
     * }
     */
    private function publishShuffledMultiItemPlatformAssessment(User $sa, User $reviewer, string $suffix): array
    {
        $subject = $this->subjects()->create($sa, 'math_'.$suffix, 'Math '.$suffix, 'create_subj');
        $draft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, 'math_'.$suffix, 'Math', '1.0', 'prog');
        $unit = $this->units()->create($draft, $sa, 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_'.$suffix, 'Outcome', 1, 'create_lo');
        $this->programs()->publish($draft, $sa, 'pub_curr');

        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $questions = [];
        $revisions = [];
        for ($i = 1; $i <= 3; ++$i) {
            $question = $this->createPublishedPlatformQuestion($sa, $reviewer, $subject, $lo, $suffix.'_q'.$i);
            $qRevision = $qRevisions->findForQuestionNumber($question, 1);
            self::assertInstanceOf(QuestionRevision::class, $qRevision);
            $questions[] = $question;
            $revisions[] = $qRevision;
        }

        $assessment = $this->assessments()->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            'Shuffled Blueprint '.$suffix,
            null,
            null,
            3600,
            NavigationMode::Free,
            QuestionOrderMode::Shuffle,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [
                [
                    'title' => 'Section A',
                    'position' => 1,
                    'questionOrderMode' => QuestionOrderMode::Shuffle,
                    'items' => [
                        [
                            'questionId' => $questions[0]->getId(),
                            'questionRevisionId' => $revisions[0]->getId(),
                            'position' => 1,
                            'points' => '1.00',
                            'penaltyPoints' => '0.00',
                            'required' => true,
                        ],
                        [
                            'questionId' => $questions[1]->getId(),
                            'questionRevisionId' => $revisions[1]->getId(),
                            'position' => 2,
                            'points' => '1.00',
                            'penaltyPoints' => '0.00',
                            'required' => true,
                        ],
                    ],
                ],
                [
                    'title' => 'Section B',
                    'position' => 2,
                    'questionOrderMode' => QuestionOrderMode::Shuffle,
                    'items' => [[
                        'questionId' => $questions[2]->getId(),
                        'questionRevisionId' => $revisions[2]->getId(),
                        'position' => 1,
                        'points' => '1.00',
                        'penaltyPoints' => '0.00',
                        'required' => true,
                    ]],
                ],
            ],
            'create_a_shuf',
        );
        $this->assessments()->submitForReview($assessment, $sa, 'submit_a_shuf');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        $this->assessments()->publish($assessment, $reviewer, 'publish_a_shuf');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        /** @var AssessmentPublicationRepository $pubs */
        $pubs = static::getContainer()->get(AssessmentPublicationRepository::class);
        $publication = $pubs->findOneBy(['assessment' => $assessment, 'publicationNumber' => 1]);
        self::assertInstanceOf(AssessmentPublication::class, $publication);

        return [
            'assessment' => $assessment,
            'publication' => $publication,
            'questions' => $questions,
            'revisions' => $revisions,
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function defaultWindow(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [$now->modify('-1 hour'), $now->modify('+7 days')];
    }

    private function deliveries(): AssessmentDeliveryManager
    {
        $s = static::getContainer()->get(AssessmentDeliveryManager::class);
        self::assertInstanceOf(AssessmentDeliveryManager::class, $s);

        return $s;
    }

    private function accessGate(): AssessmentDeliveryAccessGate
    {
        $s = static::getContainer()->get(AssessmentDeliveryAccessGate::class);
        self::assertInstanceOf(AssessmentDeliveryAccessGate::class, $s);

        return $s;
    }

    private function assessments(): AssessmentManager
    {
        $s = static::getContainer()->get(AssessmentManager::class);
        self::assertInstanceOf(AssessmentManager::class, $s);

        return $s;
    }

    private function questions(): QuestionManager
    {
        $s = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $s);

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

    private function institutionCreator(): InstitutionCreator
    {
        $s = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $s);

        return $s;
    }

    private function institutionStatus(): InstitutionStatusManager
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

    private function teacherManager(): ClassroomTeacherAssignmentManager
    {
        $s = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
        self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $s);

        return $s;
    }

    private function enrollmentManager(): ClassroomStudentEnrollmentManager
    {
        $s = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
        self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $s);

        return $s;
    }
}
