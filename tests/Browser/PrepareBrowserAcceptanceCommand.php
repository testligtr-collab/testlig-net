<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Dto\StudentProfileRequest;
use App\Entity\LearningDocumentAsset;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResourceAccessClass;
use App\Enum\ResultReleasePolicy;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\AccessPackageManager;
use App\Service\AssessmentManager;
use App\Service\CatalogTopicLessonManager;
use App\Service\CatalogWriteService;
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
use App\Service\LearningContentManager;
use App\Service\LearningDocumentManager;
use App\Service\ParentStudentLinkCodeManager;
use App\Service\QuestionManager;
use App\Service\StudentProfileManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Builds one deterministic synthetic world for the browser suite.
 *
 * Registered only in the test container. There is no HTTP route.
 */
#[AsCommand(
    name: 'app:browser-acceptance:prepare',
    description: 'Create synthetic browser-acceptance data in the test database.',
)]
final class PrepareBrowserAcceptanceCommand extends Command
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly UserFactory $userFactory,
        private readonly UserRepository $users,
        private readonly SubjectManager $subjects,
        private readonly CurriculumProgramManager $programs,
        private readonly CurriculumUnitManager $curriculumUnits,
        private readonly CurriculumTopicManager $curriculumTopics,
        private readonly CurriculumLearningOutcomeManager $outcomes,
        private readonly CatalogWriteService $catalog,
        private readonly LearningContentManager $contents,
        private readonly LearningDocumentManager $documents,
        private readonly AccessPackageManager $access,
        private readonly CatalogTopicLessonManager $placements,
        private readonly QuestionManager $questions,
        private readonly AssessmentManager $assessments,
        private readonly QuestionRevisionRepository $questionRevisions,
        private readonly InstitutionCreator $institutions,
        private readonly InstitutionStatusManager $institutionStatus,
        private readonly InstitutionMembershipManager $memberships,
        private readonly AcademicYearManager $years,
        private readonly ClassroomManager $classrooms,
        private readonly ClassroomTeacherAssignmentManager $teacherAssignments,
        private readonly ClassroomStudentEnrollmentManager $enrollments,
        private readonly StudentProfileManager $profiles,
        private readonly ParentStudentLinkCodeManager $parentLinks,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('test' !== $this->kernel->getEnvironment()) {
            $output->writeln('Browser acceptance data can be prepared only when APP_ENV=test.');

            return Command::FAILURE;
        }

        $superAdmin = $this->account('browser-sa@example.test', 'Deneme', 'Ust', UserRole::SuperAdmin);
        $admin = $this->account('browser-admin@example.test', 'Deneme', 'Yonetici', UserRole::Admin);
        $teacher = $this->account('browser-teacher@example.test', 'Deneme', 'Ogretmen', UserRole::Teacher);
        $this->account('browser-moderator@example.test', 'Deneme', 'Moderator', UserRole::Moderator);
        $this->account('browser-expert@example.test', 'Deneme', 'Uzman', UserRole::ExpertTeacher);
        $this->account('browser-teacher-empty@example.test', 'Deneme', 'Bosogretmen', UserRole::Teacher);
        $student = $this->account('browser-student@example.test', 'Deneme', 'Ogrenci', UserRole::Student);
        $otherStudent = $this->account('browser-other@example.test', 'Deneme', 'Diger', UserRole::Student);
        $parent = $this->account('browser-parent@example.test', 'Deneme', 'Veli', UserRole::Parent);
        $this->account('browser-parent-empty@example.test', 'Deneme', 'Bos', UserRole::Parent);
        $owner = $this->account('browser-owner@example.test', 'Deneme', 'Sahip', UserRole::InstitutionManager);

        $this->profiles->completeOnboarding($student, $this->profile(GradeLevel::Grade1));
        $this->profiles->completeOnboarding($otherStudent, $this->profile(GradeLevel::Grade1));

        $subject = $this->subjects->create($superAdmin, 'browser_subj', 'Tarayici dersi', 'browser_create');
        $program = $this->programs->createDraft($subject, $superAdmin, GradeLevel::Grade1, 'browser_prog', 'Tarayici programi', '1.0', 'browser_create');
        $curriculumUnit = $this->curriculumUnits->create($program, $superAdmin, 'browser_u', 'Tarayici unite', 1, 'browser_create');
        $curriculumTopic = $this->curriculumTopics->createRoot($curriculumUnit, $superAdmin, 'browser_t', 'Tarayici konu', 1, 'browser_create');
        $outcome = $this->outcomes->create($curriculumTopic, $superAdmin, 'browser_lo', 'Tarayici kazanimi', 1, 'browser_create');
        $this->programs->publish($program, $superAdmin, 'browser_publish');

        $catalogSubject = $this->catalog->createSubject(GradeLevel::Grade1, 'Matematik', null, 1, 'matematik');
        $this->catalog->assignCanonicalSubject($superAdmin, $catalogSubject->getId(), $subject->getId(), 'browser_map');
        $unit = $this->catalog->createUnit($catalogSubject->getId(), 'Sayilar', null, 0, 'sayilar');
        $topic = $this->catalog->createTopic($unit->getId(), 'Toplama', 'Sentetik toplama konusu', 0, 15, 'toplama');
        $this->catalog->publishSubject($catalogSubject->getId());
        $this->catalog->publishUnit($unit->getId());
        $this->catalog->publishTopic($topic->getId());

        $pdf = $this->receivePdf($teacher, 'calisma-notu.pdf');
        $secret = $this->kernel->getContainer()->getParameter('kernel.secret');
        if (!\is_string($secret) || '' === $secret) {
            $output->writeln('Test kernel secret is missing.');

            return Command::FAILURE;
        }
        $this->documents->markReady($admin, hash_hmac('sha256', $pdf->getId()->toRfc4122(), $secret));

        $published = $this->contents->createDraft(
            $teacher,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'browser_topic',
            'Toplama anlatimi',
            'Sentetik ders ozeti',
            $this->lessonDocument($pdf->getId()->toRfc4122()),
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            'browser_create',
        );
        $this->contents->submitForReview($published, $teacher, 'browser_submit');
        $this->contents->publish($published, $admin, 'browser_publish');
        $this->access->setLearningContentAccessPolicy($published, $admin, ResourceAccessClass::Free, 'browser_free');
        $lesson = $this->placements->create($admin, $topic->getId(), $published->getId(), 'Toplama anlatimi', null, 0, 'browser_place', 'toplama-anlatimi');
        $this->placements->publish($admin, $lesson->getId(), 'browser_publish');

        $draft = $this->contents->createDraft(
            $teacher,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'browser_draft',
            'Duzenlenen anlatim',
            null,
            LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Taslak ilk paragraf'],
                    ['type' => 'paragraph', 'text' => 'Taslak ikinci paragraf'],
                ],
            ]),
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            'browser_create',
        );

        $review = $this->contents->createDraft(
            $teacher,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'browser_review',
            'Yayin bekleyen anlatim',
            null,
            LearningContentDocument::paragraph('Inceleme metni'),
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            'browser_create',
        );
        $this->contents->submitForReview($review, $teacher, 'browser_submit');
        $this->contents->createDraft(
            $admin,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            LearningContentType::TopicExplanation,
            'browser_secret',
            'Gizli admin taslagi',
            null,
            LearningContentDocument::paragraph('Gizli metin'),
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            'browser_create',
        );

        $publishedQuestion = $this->questions->createDraftQuestion(
            $teacher,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Iki arti iki kactir'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Uc'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Dort'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'browser_q_pub',
        );
        $this->questions->submitForReview($publishedQuestion, $teacher, 'browser_submit');
        $this->questions->publish($publishedQuestion, $admin, 'browser_publish');
        $questionRevision = $this->questionRevisions->findForQuestionNumber($publishedQuestion, $publishedQuestion->getCurrentRevisionNumber());
        if (null === $questionRevision) {
            $output->writeln('Published question revision was not found.');

            return Command::FAILURE;
        }

        $assessment = $this->assessments->createDraftAssessment(
            $teacher,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade1,
            'Toplama testi',
            null,
            null,
            null,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Manual,
            null,
            [[
                'title' => 'Bolum',
                'instructions' => null,
                'position' => 1,
                'durationSeconds' => null,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $publishedQuestion->getId(),
                    'questionRevisionId' => $questionRevision->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'browser_test',
            $subject,
        );
        $this->assessments->submitForReview($assessment, $teacher, 'browser_submit');
        $this->assessments->publish($assessment, $admin, 'browser_publish');

        $draftQuestion = $this->questions->createDraftQuestion(
            $teacher,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade1,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Editor sorusu'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Birinci secenek'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Ikinci secenek'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_a'],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'browser_q_draft',
        );

        $institution = $this->institutions->create($superAdmin, $owner, 'Tarayici Okulu', InstitutionType::School, 'browser_create');
        $this->institutionStatus->activate($institution, $superAdmin, 'browser_activate');
        $year = $this->years->createPlanned(
            $institution,
            $owner,
            '2026-2027',
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2027-06-15'),
            'browser_create',
        );
        $this->years->activate($year, $owner, 'browser_activate');
        $classroom = $this->classrooms->create($year, $owner, 'Sinif A', GradeLevel::Grade1, 'browser_create', 'A', 24);
        $teacherMembership = $this->memberships->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'browser_create');
        $studentMembership = $this->memberships->addMember($institution, $owner, $student, InstitutionMembershipRole::Student, 'browser_create');
        $this->teacherAssignments->assign($classroom, $owner, $teacherMembership, TeacherAssignmentRole::HomeroomTeacher, 'browser_create');
        $endedClassroom = $this->classrooms->create($year, $owner, 'Eski Sinif', GradeLevel::Grade1, 'browser_create', 'B', 24);
        $endedAssignment = $this->teacherAssignments->assign($endedClassroom, $owner, $teacherMembership, TeacherAssignmentRole::HomeroomTeacher, 'browser_create');
        $this->teacherAssignments->endAssignment($endedAssignment, $owner, 'browser_end');
        $this->enrollments->enroll($classroom, $owner, $studentMembership, 'browser_create');

        $issued = $this->parentLinks->issue($student);
        $this->parentLinks->redeem($parent, $issued->displayCode, '127.0.0.1');

        $manifest = [
            'password' => self::PASSWORD,
            'users' => [
                'superadmin' => 'browser-sa@example.test',
                'admin' => 'browser-admin@example.test',
                'teacher' => 'browser-teacher@example.test',
                'moderator' => 'browser-moderator@example.test',
                'expert' => 'browser-expert@example.test',
                'emptyTeacher' => 'browser-teacher-empty@example.test',
                'student' => 'browser-student@example.test',
                'otherStudent' => 'browser-other@example.test',
                'parent' => 'browser-parent@example.test',
                'parentUnlinked' => 'browser-parent-empty@example.test',
                'owner' => 'browser-owner@example.test',
            ],
            'paths' => [
                'topic' => \sprintf('/ogrenci/dersler/%s/%s/%s', $catalogSubject->getSlug(), $unit->getSlug(), $topic->getSlug()),
                'testCode' => $assessment->getCode(),
                'revision' => '/yonetim/icerikler/'.$draft->getId()->toRfc4122().'/revision',
                'reviewDetail' => '/yonetim/icerikler/'.$review->getId()->toRfc4122(),
                'questionEdit' => '/yonetim/sorular/'.$draftQuestion->getId()->toRfc4122().'/duzenle',
                'classroomName' => 'Sinif A',
                'contentTitle' => 'Toplama anlatimi',
                'testTitle' => 'Toplama testi',
                'foreignTitle' => 'Gizli admin taslagi',
                'endedClassroom' => 'Eski Sinif',
            ],
        ];
        $path = $this->kernel->getProjectDir().\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'browser-acceptance.json';
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0775, true) && !is_dir(\dirname($path))) {
            $output->writeln('Could not create the var directory.');

            return Command::FAILURE;
        }
        $encoded = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        if (false === file_put_contents($path, $encoded)) {
            $output->writeln('Could not write the browser acceptance manifest.');

            return Command::FAILURE;
        }
        @chmod($path, 0600);
        $output->writeln('Browser acceptance manifest is ready.');

        return Command::SUCCESS;
    }

    private function account(string $email, string $firstName, string $lastName, UserRole $role): User
    {
        $createAs = \in_array($role, [UserRole::Admin, UserRole::SuperAdmin], true) ? UserRole::Student : $role;
        $user = $this->userFactory->createAndPersist($email, self::PASSWORD, $firstName, $lastName, $createAs);
        if ($createAs !== $role) {
            $user->setGlobalRoles([$role]);
        }
        $user->markEmailVerified(new \DateTimeImmutable('2026-09-28T00:00:00+00:00'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function profile(GradeLevel $grade): StudentProfileRequest
    {
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $grade;

        return $dto;
    }

    private function receivePdf(User $actor, string $name): LearningDocumentAsset
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        if (false === $path) {
            throw new \RuntimeException('Temporary PDF path could not be created.');
        }
        file_put_contents($path, "%PDF-1.4\n".$name."\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $file = new UploadedFile($path, $name, 'application/pdf', \UPLOAD_ERR_OK, true);

        return $this->documents->receive($actor, $file);
    }

    private function lessonDocument(string $assetId): LearningContentDocument
    {
        return LearningContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Toplama'],
                ['type' => 'paragraph', 'text' => 'Iki sayiyi toplariz'],
                ['type' => 'list', 'items' => ['Bir', 'Iki']],
                ['type' => 'callout', 'variant' => 'tip', 'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Once onluklari yaz'],
                ]],
                ['type' => 'quote', 'text' => 'Toplama biriktirmektir'],
                ['type' => 'math', 'latex' => '2 + 2 = 4'],
                [
                    'type' => 'video',
                    'provider' => 'youtube',
                    'providerVideoId' => 'BrowserVid1',
                    'title' => 'Konu videosu',
                    'description' => 'Kisa aciklama',
                ],
                ['type' => 'document', 'assetId' => $assetId, 'label' => 'Calisma notu'],
            ],
        ]);
    }
}
