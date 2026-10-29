<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionFailureReason;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Enum\SubjectStatus;
use App\Exception\LearningContentException;
use App\Exception\QuestionException;
use App\Presentation\ContentWorkflowReason;
use App\Presentation\QuestionWorkflowProgress;
use App\Question\Content\QuestionAuthorDocument;
use App\Question\Content\QuestionContentView;
use App\Question\Content\QuestionPlainText;
use App\Question\Image\QuestionImagePlacement;
use App\Question\Image\QuestionImageRegistrar;
use App\Question\Image\QuestionRevisionPendingImage;
use App\Question\Image\Scan\QuestionImageScanAction;
use App\Question\Usage\QuestionTestUsage;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionRevisionAlignmentRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SubjectRepository;
use App\Security\AdminAuthorization;
use App\Security\AdminPermission;
use App\Security\QuestionPermission;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\QuestionSingleChoiceForm;
use App\Service\QuestionManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminQuestionController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly QuestionRepository $questions,
        private readonly QuestionRevisionRepository $revisions,
        private readonly QuestionRevisionOptionRepository $options,
        private readonly QuestionRevisionAlignmentRepository $alignments,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly SubjectRepository $subjects,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly QuestionManager $questionManager,
        private readonly QuestionSingleChoiceForm $form,
        private readonly ContentWorkflowReason $workflowReason,
        private readonly QuestionWorkflowProgress $workflowProgress,
        private readonly QuestionImageRegistrar $images,
        private readonly QuestionImagePlacement $imagePlacement,
        private readonly QuestionRevisionPendingImage $pendingImages,
        private readonly QuestionImageScanAction $imageScans,
        private readonly QuestionTestUsage $testUsage,
        private readonly ActiveVerifiedUserPolicy $actors,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/sorular', name: 'app_admin_questions', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function index(Request $request): Response
    {
        $actor = $this->requireActorUser();
        $seeAll = $this->canSeeAll($actor);
        $subjects = $this->subjects->findActiveOrdered();
        $filters = $this->listFilters($request, $subjects);
        $questions = $this->questions->findPlatformVisibleFiltered(
            $actor,
            $seeAll,
            $filters['status'],
            $filters['grade'],
            $filters['subject'],
            $filters['text'],
            $filters['difficulties'],
            $filters['blocked'],
        );
        $revisions = $this->revisions->findCurrentForQuestions($questions);
        $canViewTests = $this->adminAuthorization->canViewTestBank($actor);
        $usages = $canViewTests ? $this->testUsage->forQuestions($questions) : [];
        $rows = [];
        foreach ($questions as $question) {
            $revision = $revisions[$question->getId()->toRfc4122()] ?? null;
            if (!$revision instanceof QuestionRevision) {
                continue;
            }
            $lines = $this->plainLines($revision->getStemContent());
            $questionId = $question->getId()->toRfc4122();
            $rows[] = [
                'id' => $questionId,
                'code' => $question->getCode(),
                'summary' => $lines[0] ?? 'Soru metni yok',
                'subject' => $question->getSubject()->getName(),
                'grade' => $question->getGradeLevel()->value.'. sınıf',
                'type' => $this->typeLabel($revision->getType()),
                'difficulty' => $this->difficultyLabel($revision->getDifficulty()),
                'status' => $question->getStatus()->value,
                'revision' => $question->getCurrentRevisionNumber(),
                'usages' => $usages[$questionId] ?? [],
            ];
        }

        return $this->renderAdmin('admin/questions/list.html.twig', [
            'rows' => $rows,
            'counts' => $this->questions->countPlatformVisibleByStatus($actor, $seeAll),
            'subjects' => $subjects,
            'filters' => [
                'q' => $filters['text'],
                'status' => $filters['status'] instanceof QuestionStatus ? $filters['status']->value : '',
                'subject_id' => $filters['subject'] instanceof Uuid ? $filters['subject']->toRfc4122() : '',
                'grade' => $filters['grade'] instanceof GradeLevel ? (string) $filters['grade']->value : '',
                'difficulty' => $filters['bucket'],
            ],
            'can_author' => $this->adminAuthorization->canAuthorQuestions($actor),
            'can_view_tests' => $canViewTests,
            'capped' => 100 === \count($rows),
        ]);
    }

    #[Route('/yonetim/sorular/yeni', name: 'app_admin_question_new', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function new(Request $request): Response
    {
        $actor = $this->requireActorUser();
        if (!$this->adminAuthorization->canAuthorQuestions($actor)) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $this->assertEditorCsrf($request);

            return $this->saveNew($request, $actor);
        }

        return $this->renderForm($request, null);
    }

    #[Route('/yonetim/sorular/{id}', name: 'app_admin_question_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function show(string $id): Response
    {
        $question = $this->visibleQuestion($id);
        $revision = $this->currentRevision($question);
        $optionRows = $this->options->findByRevision($revision);
        $canAnswer = $this->isGranted(QuestionPermission::ANSWER_KEY_VIEW, $question);
        $actor = $this->requireActorUser();
        $isAuthor = $revision->getCreatedBy()->getId()->equals($actor->getId());
        $stemLines = $this->plainLines($revision->getStemContent());
        $outcome = $this->primaryOutcome($revision);

        return $this->renderAdmin('admin/questions/detail.html.twig', [
            'question' => $question,
            'revision_number' => $revision->getRevisionNumber(),
            'heading' => $stemLines[0] ?? 'Soru',
            'stem_lines' => $stemLines,
            'stem_blocks' => QuestionContentView::blocks($revision->getStemContent(), true),
            'explanation_lines' => $this->plainLines($revision->getExplanationContent()),
            'explanation_blocks' => QuestionContentView::blocks($revision->getExplanationContent(), true),
            'option_lines' => $this->optionLines($optionRows),
            'option_blocks' => $this->optionBlocks($optionRows, true),
            'usages' => $this->adminAuthorization->canViewTestBank($actor) ? ($this->testUsage->forQuestions([$question])[$question->getId()->toRfc4122()] ?? []) : [],
            'can_view_tests' => $this->adminAuthorization->canViewTestBank($actor),
            'correct_position' => $canAnswer ? $this->correctPosition($revision, $optionRows) : null,
            'status_label' => $this->statusLabels()[$question->getStatus()->value] ?? 'Bilinmiyor',
            'difficulty_label' => $this->difficultyLabel($revision->getDifficulty()),
            'type_label' => $this->typeLabel($revision->getType()),
            'grade_label' => $question->getGradeLevel()->value.'. sınıf',
            'subject_name' => $question->getSubject()->getName(),
            'program_name' => $outcome['program'],
            'outcome_code' => $outcome['code'],
            'outcome_text' => $outcome['text'],
            'published_label' => $question->getPublishedAt()?->format('d.m.Y H:i'),
            'image_note' => $this->imageNote($question, $revision, $optionRows),
            'can_edit' => $this->canEdit($question),
            'progress' => $this->workflowProgress->summarize([
                'status' => $question->getStatus()->value,
                'can_edit' => $this->canEdit($question),
                'can_submit' => QuestionStatus::Draft === $question->getStatus() && $this->isGranted(QuestionPermission::MANAGE, $question),
                'can_return' => QuestionStatus::InReview === $question->getStatus() && $this->isGranted(QuestionPermission::REVIEW, $question),
                'can_publish' => QuestionStatus::InReview === $question->getStatus() && $this->isGranted(QuestionPermission::PUBLISH, $question),
                'can_archive' => QuestionStatus::Published === $question->getStatus() && $this->isGranted(QuestionPermission::MANAGE, $question),
                'is_revision_author' => $isAuthor,
            ]),
            'can_scan_image' => $this->canScanImage($actor, $question, $revision),
        ]);
    }

    #[Route('/yonetim/sorular/{id}/gorsel-tara', name: 'app_admin_question_image_scan', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function scanImage(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('question_image_scan', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Oturum doğrulaması başarısız.');
        }
        $question = $this->visibleQuestion($id);
        $actor = $this->requireActorUser();
        $revision = $this->currentRevision($question);
        try {
            $message = $this->imageScans->scan($actor, $question, $revision);
        } catch (LearningContentException $exception) {
            if ('unauthorized' === $exception->getReason()->value) {
                throw $this->createAccessDeniedException();
            }
            $message = 'Tarama tamamlanamadı.';
        } catch (QuestionException) {
            $message = 'Tarama tamamlanamadı.';
        }
        $this->addFlash('success' === $this->flashKind($message) ? 'success' : 'error', $message);
        $response = $this->redirectToRoute('app_admin_question_show', ['id' => $question->getId()->toRfc4122()]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/sorular/{id}/duzenle', name: 'app_admin_question_edit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function edit(Request $request, string $id): Response
    {
        $question = $this->visibleQuestion($id);
        if (!$this->canEdit($question)) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST')) {
            $this->assertEditorCsrf($request);

            return $this->saveRevision($request, $question);
        }

        return $this->renderForm($request, $question);
    }

    #[Route('/yonetim/sorular/{id}/gorunum', name: 'app_admin_question_preview', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function preview(string $id): Response
    {
        $question = $this->visibleQuestion($id);
        $revision = $this->currentRevision($question);
        $options = $this->options->findByRevision($revision);

        return $this->renderAdmin('admin/questions/preview.html.twig', [
            'question' => $question,
            'stem_lines' => $this->plainLines($revision->getStemContent()),
            'stem_blocks' => QuestionContentView::blocks($revision->getStemContent(), true),
            'explanation_lines' => $this->plainLines($revision->getExplanationContent()),
            'explanation_blocks' => QuestionContentView::blocks($revision->getExplanationContent(), true),
            'option_lines' => $this->optionLines($options),
            'option_blocks' => $this->optionBlocks($options, true),
        ]);
    }

    #[Route('/yonetim/sorular/{id}/incelemeye-gonder', name: 'app_admin_question_submit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function submit(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'submit');
    }

    #[Route('/yonetim/sorular/{id}/taslaga-dondur', name: 'app_admin_question_return', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function returnToDraft(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'return');
    }

    #[Route('/yonetim/sorular/{id}/yayinla', name: 'app_admin_question_publish', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function publish(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'publish');
    }

    #[Route('/yonetim/sorular/{id}/arsivle', name: 'app_admin_question_archive', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function archive(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'archive');
    }

    private function saveNew(Request $request, User $actor): Response
    {
        try {
            $parsed = $this->form->parse($request);
            $subject = $this->requireSubject($request->request->getString('subject_id'));
            $outcome = $this->requireOutcome($parsed['outcomeId'], $subject, $parsed['grade']);
            $staged = null;
            $bytes = $this->uploadedBytes($request);
            if (null !== $bytes) {
                $staged = $this->images->stage($actor, QuestionScope::Platform, null, $bytes, $this->declaredMime($request));
            }
            $placed = $this->imagePlacement->apply(
                $parsed['stem'],
                $parsed['explanation'],
                $parsed['options'],
                null,
                $staged?->assetId,
                $request->request->getString('image_target'),
            );
            $question = $this->images->finish($staged, fn (): Question => $this->questionManager->createDraftQuestion(
                $actor,
                QuestionScope::Platform,
                null,
                $subject,
                $parsed['grade'],
                QuestionType::SingleChoice,
                $placed['stem'],
                $placed['explanation'],
                $placed['options'],
                $parsed['answerSpec'],
                [['learningOutcome' => $outcome, 'isPrimary' => true]],
                $parsed['difficulty'],
                'question_draft_saved',
                image: $staged,
            ));
            $this->addFlash('success', 'Taslak kaydedildi.');

            return $this->redirectToRoute('app_admin_question_show', ['id' => $question->getId()->toRfc4122()]);
        } catch (QuestionException $e) {
            return $this->renderQuestionError($request, null, $e);
        }
    }

    private function saveRevision(Request $request, Question $question): Response
    {
        $actor = $this->requireActorUser();
        if ($request->request->getInt('expected_revision') !== $question->getCurrentRevisionNumber()) {
            $this->addFlash('error', 'Soru bu sırada başka bir işlemle değişti. Sayfayı yenileyip tekrar deneyin.');

            return $this->redirectToRoute('app_admin_question_edit', ['id' => $question->getId()->toRfc4122()]);
        }

        try {
            $parsed = $this->form->parse($request);
            $subject = $this->requireSubject($request->request->getString('subject_id'));
            if (!$subject->getId()->equals($question->getSubject()->getId())) {
                throw QuestionException::invalidInput('Subject cannot change.');
            }
            $outcome = $this->requireOutcome($parsed['outcomeId'], $subject, $parsed['grade']);
            $staged = null;
            $bytes = $this->uploadedBytes($request);
            if (null !== $bytes) {
                $staged = $this->images->stage($actor, QuestionScope::Platform, null, $bytes, $this->declaredMime($request));
            }
            $existing = null;
            if (null === $staged && '1' === $request->request->getString('keep_image')) {
                $existing = $this->currentImageBlock($question);
            }
            $target = $request->request->getString('image_target');
            if (null !== $existing && '' === $target) {
                $target = $existing['slot'];
            }
            $placed = $this->imagePlacement->apply(
                $parsed['stem'],
                $parsed['explanation'],
                $parsed['options'],
                null !== $existing ? $existing['block'] : null,
                $staged?->assetId,
                $target,
            );
            $this->images->finish($staged, fn (): QuestionRevision => $this->questionManager->createRevision(
                $question,
                $actor,
                QuestionType::SingleChoice,
                $placed['stem'],
                $placed['explanation'],
                $placed['options'],
                $parsed['answerSpec'],
                [['learningOutcome' => $outcome, 'isPrimary' => true]],
                $parsed['difficulty'],
                'question_revision_saved',
                image: $staged,
            ));
            $this->addFlash('success', 'Taslak kaydedildi.');
        } catch (QuestionException $e) {
            return $this->renderQuestionError($request, $question, $e);
        }

        return $this->redirectToRoute('app_admin_question_show', ['id' => $question->getId()->toRfc4122()]);
    }

    private function transition(Request $request, string $id, string $action): Response
    {
        $this->assertEditorCsrf($request);
        $question = $this->visibleQuestion($id);
        $actor = $this->requireActorUser();
        if ($request->request->getInt('expected_revision') !== $question->getCurrentRevisionNumber()) {
            $this->addFlash('error', 'Soru bu sırada başka bir işlemle değişti. Sayfayı yenileyip tekrar deneyin.');

            return $this->redirectToRoute('app_admin_question_show', ['id' => $question->getId()->toRfc4122()]);
        }

        try {
            $resolved = $this->workflowReason->resolve(
                $this->defaultReason($action),
                $request->request->get('reason_code'),
                $request->request->get('operator_note'),
            );
            match ($action) {
                'submit' => $this->questionManager->submitForReview($question, $actor, $resolved['code'], $resolved['operator_note']),
                'return' => $this->questionManager->returnToDraft($question, $actor, $resolved['code'], $resolved['operator_note']),
                'publish' => $this->questionManager->publish($question, $actor, $resolved['code'], $resolved['operator_note']),
                'archive' => $this->questionManager->archive($question, $actor, $resolved['code'], $resolved['operator_note']),
                default => throw QuestionException::invalidInput('Unknown action.'),
            };
            $this->addFlash('success', $this->successMessage($action));
        } catch (QuestionException $e) {
            return $this->redirectQuestionError('app_admin_question_show', ['id' => $question->getId()->toRfc4122()], $e);
        } catch (LearningContentException) {
            $this->addFlash('error', 'İşlem notu kaydedilemedi.');
        }

        return $this->redirectToRoute('app_admin_question_show', ['id' => $question->getId()->toRfc4122()]);
    }

    /**
     * @param array{summary: string, fields: array<string, string>} $errors
     */
    private function renderForm(Request $request, ?Question $question, array $errors = ['summary' => '', 'fields' => []]): Response
    {
        $subjects = $this->subjects->findActiveOrdered();
        $subject = $this->selectedSubject($request, $subjects, $question);
        $grade = $this->selectedGrade($request, $question);
        $values = $this->formValues($question);
        if ($request->isMethod('POST') && '' !== $errors['summary']) {
            $values = $this->postedValues($request, $values);
        }
        $outcomeChoices = null !== $subject ? $this->activeOutcomes($subject, $grade) : [];

        return $this->renderAdmin('admin/questions/form.html.twig', [
            'question' => $question,
            'subjects' => $subjects,
            'subject_id' => $subject?->getId()->toRfc4122() ?? '',
            'grade' => $grade->value,
            'grades' => GradeLevel::cases(),
            'difficulties' => [
                QuestionDifficulty::Easy->value => 'Kolay',
                QuestionDifficulty::Medium->value => 'Orta',
                QuestionDifficulty::Hard->value => 'Zor',
            ],
            'outcomes' => $outcomeChoices,
            'values' => $values,
            'errors' => $errors,
            'can_submit' => $question instanceof Question
                && QuestionStatus::Draft === $question->getStatus()
                && $this->isGranted(QuestionPermission::MANAGE, $question),
            'expected_revision' => $question?->getCurrentRevisionNumber() ?? 0,
        ], '' !== $errors['summary'] ? new Response('', 422) : null);
    }

    private function renderQuestionError(Request $request, ?Question $question, QuestionException $exception): Response
    {
        if (QuestionFailureReason::Unauthorized === $exception->getReason()) {
            throw $this->createAccessDeniedException();
        }
        $response = $this->renderForm($request, $question, $this->formErrors($exception));
        if ($this->getParameter('kernel.debug')) {
            $response->headers->set('X-Question-Failure', $exception->getMessage());
        }

        return $response;
    }

    private function canScanImage(User $actor, Question $question, QuestionRevision $revision): bool
    {
        return $this->actors->isActiveVerifiedSuperAdmin($actor)
            && null !== $this->pendingImages->sole($question, $revision);
    }

    private function flashKind(string $message): string
    {
        return 'Görsel tarandı ve kullanıma hazır.' === $message ? 'success' : 'error';
    }

    private function visibleQuestion(string $id): Question
    {
        try {
            $questionId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $question = $this->questions->findOneById($questionId);
        if (!$question instanceof Question || QuestionScope::Platform !== $question->getScope()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isGranted(QuestionPermission::VIEW, $question)) {
            throw $this->createNotFoundException();
        }

        return $question;
    }

    private function currentRevision(Question $question): QuestionRevision
    {
        $revision = $this->revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        if (!$revision instanceof QuestionRevision) {
            throw $this->createNotFoundException();
        }

        return $revision;
    }

    private function canEdit(Question $question): bool
    {
        return QuestionStatus::Draft === $question->getStatus()
            && $this->isGranted(QuestionPermission::MANAGE, $question);
    }

    private function canSeeAll(User $actor): bool
    {
        return !$this->isTeacherOnly($actor);
    }

    private function isTeacherOnly(User $actor): bool
    {
        $roles = $actor->getRoles();

        return \in_array('ROLE_TEACHER', $roles, true)
            && !\in_array('ROLE_ADMIN', $roles, true)
            && !\in_array('ROLE_HEAD_TEACHER', $roles, true)
            && !\in_array('ROLE_EXPERT_TEACHER', $roles, true)
            && !\in_array('ROLE_SUPER_ADMIN', $roles, true)
            && !\in_array('ROLE_MODERATOR', $roles, true);
    }

    private function assertEditorCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('question_editor', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Oturum doğrulaması başarısız.');
        }
    }

    private function requireSubject(string $id): Subject
    {
        try {
            $subjectId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw QuestionException::invalidInput('Subject is required.');
        }
        $subject = $this->subjects->find($subjectId);
        if (!$subject instanceof Subject || SubjectStatus::Archived === $subject->getStatus()) {
            throw QuestionException::invalidInput('Subject is required.');
        }

        return $subject;
    }

    private function requireOutcome(string $id, Subject $subject, GradeLevel $grade): CurriculumLearningOutcome
    {
        try {
            $outcomeId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw QuestionException::alignmentInvalid('Outcome is required.');
        }
        $outcome = $this->outcomes->findOneById($outcomeId);
        if (!$outcome instanceof CurriculumLearningOutcome) {
            throw QuestionException::alignmentInvalid('Outcome is required.');
        }
        foreach ($this->activeOutcomes($subject, $grade) as $allowed) {
            if ($allowed->getId()->equals($outcome->getId())) {
                return $outcome;
            }
        }

        throw QuestionException::alignmentInvalid('Outcome is not active for this subject and grade.');
    }

    /**
     * @param list<Subject> $subjects
     */
    private function selectedSubject(Request $request, array $subjects, ?Question $question): ?Subject
    {
        $raw = $request->isMethod('POST') ? $request->request->getString('subject_id') : $request->query->getString('subject_id');
        if ('' === $raw && $question instanceof Question) {
            $raw = $question->getSubject()->getId()->toRfc4122();
        }
        foreach ($subjects as $subject) {
            if ($subject->getId()->toRfc4122() === $raw) {
                return $subject;
            }
        }

        return $subjects[0] ?? null;
    }

    private function selectedGrade(Request $request, ?Question $question): GradeLevel
    {
        $raw = $request->isMethod('POST') ? $request->request->getInt('grade') : $request->query->getInt('grade');
        if (0 === $raw && $question instanceof Question && !$request->query->has('grade')) {
            return $question->getGradeLevel();
        }
        $grade = GradeLevel::tryFrom($raw);

        return $grade ?? GradeLevel::Grade1;
    }

    /**
     * @return list<CurriculumLearningOutcome>
     */
    private function activeOutcomes(Subject $subject, GradeLevel $grade): array
    {
        $rows = [];
        foreach ($this->outcomes->findActiveOrderedForSubject($subject) as $outcome) {
            $program = $outcome->getCurriculumProgram();
            if (CurriculumStatus::Published !== $program->getStatus()) {
                continue;
            }
            if ($program->getGradeLevel() !== $grade) {
                continue;
            }
            if (!$program->getSubject()->getId()->equals($subject->getId())) {
                continue;
            }
            if (CurriculumContentStatus::Active !== $outcome->getStatus()) {
                continue;
            }
            $rows[] = $outcome;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(?Question $question): array
    {
        $blank = [
            'stem' => '',
            'explanation' => '',
            'options' => ['', ''],
            'stem_document' => '',
            'explanation_document' => '',
            'option_documents' => ['', ''],
            'correct' => 1,
            'difficulty' => QuestionDifficulty::Medium->value,
            'outcome_id' => '',
            'has_image' => false,
            'image_slot' => 'stem',
        ];
        if (!$question instanceof Question) {
            return $blank;
        }
        $revision = $this->currentRevision($question);
        if (QuestionType::SingleChoice !== $revision->getType()) {
            throw $this->createAccessDeniedException();
        }
        $optionRows = $this->options->findByRevision($revision);
        $options = [];
        $optionDocuments = [];
        foreach ($optionRows as $option) {
            $lines = $this->plainLines($option->getContent());
            $options[] = $lines[0] ?? '';
            $optionDocuments[] = QuestionAuthorDocument::json($option->getContent());
        }
        if (\count($options) < 2) {
            $options = ['', ''];
            $optionDocuments = ['', ''];
        }
        $primary = '';
        foreach ($this->alignments->findByRevision($revision) as $alignment) {
            if ($alignment->isPrimary()) {
                $primary = $alignment->getLearningOutcome()->getId()->toRfc4122();
            }
        }
        $explanation = $this->plainLines($revision->getExplanationContent());
        $image = $this->currentImageBlock($question);

        return [
            'stem' => $this->plainLines($revision->getStemContent())[0] ?? '',
            'explanation' => $explanation[0] ?? '',
            'options' => $options,
            'stem_document' => QuestionAuthorDocument::json($revision->getStemContent()),
            'explanation_document' => QuestionAuthorDocument::json($revision->getExplanationContent()),
            'option_documents' => $optionDocuments,
            'correct' => $this->isGranted(QuestionPermission::ANSWER_KEY_VIEW, $question)
                ? ($this->correctPosition($revision, $optionRows) ?? 1)
                : 1,
            'difficulty' => $this->editorDifficulty($revision->getDifficulty()),
            'outcome_id' => $primary,
            'has_image' => null !== $image,
            'image_slot' => null !== $image ? $image['slot'] : 'stem',
        ];
    }

    /**
     * @param list<\App\Entity\QuestionRevisionOption> $optionRows
     */
    private function correctPosition(QuestionRevision $revision, array $optionRows): ?int
    {
        $key = $this->answerKeys->findOneByRevision($revision);
        if (null === $key) {
            return null;
        }
        $payload = $key->getAnswerPayload();
        $stable = $payload['correctStableKey'] ?? null;
        if (!\is_string($stable)) {
            return null;
        }
        foreach ($optionRows as $option) {
            if ($option->getStableKey() === $stable) {
                return $option->getPosition();
            }
        }

        return null;
    }

    /**
     * @param list<\App\Entity\QuestionRevisionOption> $optionRows
     *
     * @return list<string>
     */
    private function optionLines(array $optionRows): array
    {
        $lines = [];
        foreach ($optionRows as $option) {
            $text = $this->plainLines($option->getContent());
            $lines[] = $text[0] ?? '';
        }

        return $lines;
    }

    /**
     * @param array<string, mixed>|null $document
     *
     * @return list<string>
     */
    private function plainLines(?array $document): array
    {
        return QuestionPlainText::lines($document);
    }

    /**
     * @return array{program: string, code: string, text: string}
     */
    private function primaryOutcome(QuestionRevision $revision): array
    {
        $empty = ['program' => '', 'code' => '', 'text' => ''];
        foreach ($this->alignments->findByRevision($revision) as $alignment) {
            if (!$alignment->isPrimary()) {
                continue;
            }
            $outcome = $alignment->getLearningOutcome();

            return [
                'program' => $outcome->getCurriculumProgram()->getName(),
                'code' => $outcome->getCode(),
                'text' => $outcome->getDescription(),
            ];
        }

        return $empty;
    }

    /**
     * @param list<Subject> $subjects
     *
     * @return array{status: ?QuestionStatus, grade: ?GradeLevel, subject: ?Uuid, text: string, difficulties: ?list<QuestionDifficulty>, bucket: string, blocked: bool}
     */
    private function listFilters(Request $request, array $subjects): array
    {
        $status = QuestionStatus::tryFrom($request->query->getString('status'));
        $grade = GradeLevel::tryFrom($request->query->getInt('grade'));
        if (0 === $request->query->getInt('grade') && !$request->query->has('grade')) {
            $grade = null;
        }
        $text = trim($request->query->getString('q'));
        if (mb_strlen($text) > 100) {
            $text = mb_substr($text, 0, 100);
        }
        if ($this->containsMarkup($text)) {
            $text = '';
        }
        $subjectRaw = strtolower($request->query->getString('subject_id'));
        $subject = null;
        $blocked = false;
        if ('' !== $subjectRaw) {
            foreach ($subjects as $item) {
                if ($item->getId()->toRfc4122() === $subjectRaw) {
                    $subject = $item->getId();
                    break;
                }
            }
            $blocked = !$subject instanceof Uuid;
        }
        $bucket = $request->query->getString('difficulty');
        $difficulties = match ($bucket) {
            'kolay' => [QuestionDifficulty::VeryEasy, QuestionDifficulty::Easy],
            'orta' => [QuestionDifficulty::Medium],
            'zor' => [QuestionDifficulty::Hard, QuestionDifficulty::VeryHard],
            default => null,
        };
        if (!\in_array($bucket, ['kolay', 'orta', 'zor'], true)) {
            $bucket = '';
        }

        return [
            'status' => $status,
            'grade' => $grade,
            'subject' => $subject,
            'text' => $text,
            'difficulties' => $difficulties,
            'bucket' => $bucket,
            'blocked' => $blocked,
        ];
    }

    private function typeLabel(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::SingleChoice => 'Tek doğru seçenekli',
            QuestionType::MultipleChoice => 'Çoklu seçim',
            QuestionType::TrueFalse => 'Doğru / yanlış',
            QuestionType::Numeric => 'Sayısal',
            QuestionType::ShortAnswer => 'Kısa cevap',
        };
    }

    /**
     * @param list<\App\Entity\QuestionRevisionOption> $optionRows
     */
    private function imageNote(Question $question, QuestionRevision $revision, array $optionRows): ?string
    {
        if (null !== $this->pendingImages->sole($question, $revision)) {
            return 'Tarama bekliyor. Hazır olmayan görsel öğrenciye sunulmaz.';
        }
        $documents = [$revision->getStemContent(), $revision->getExplanationContent()];
        foreach ($optionRows as $option) {
            $documents[] = $option->getContent();
        }
        foreach ($documents as $document) {
            if ($this->documentHasImage($document)) {
                return 'Görsel kayıtlı. Yalnız hazır ve temiz dosya öğrenciye sunulur.';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $document
     */
    private function documentHasImage(?array $document): bool
    {
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return false;
        }
        foreach ($blocks as $block) {
            if (\is_array($block) && 'image_reference' === ($block['type'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{summary: string, fields: array<string, string>}
     */
    private function formErrors(QuestionException $exception): array
    {
        [$field, $message] = match ($exception->getMessage()) {
            'Stem is required.' => ['stem', 'Soru metni gerekli.'],
            'stem_markup' => ['stem', 'Bu alanda HTML kullanılamaz.'],
            'stem_math' => ['stem', 'Formül ifadesi kabul edilmiyor.'],
            'Option text is required.' => ['options', 'Seçenek metni gerekli.'],
            'option_markup' => ['options', 'Seçenek metninde HTML kullanılamaz.'],
            'option_math' => ['options', 'Seçenek formülü kabul edilmiyor.'],
            'Option count must be 2 to 6.' => ['options', 'En az iki, en çok altı seçenek gerekir.'],
            'Duplicate option text.' => ['options', 'Seçenekler aynı olamaz.'],
            'Exactly one correct option is required.' => ['correct', 'Bir doğru cevap seçin.'],
            'Difficulty is required.' => ['difficulty', 'Zorluk seçin.'],
            'Grade is required.' => ['grade', 'Sınıf seçin.'],
            'Outcome is required.' => ['outcome_id', 'Kazanım seçin.'],
            'Outcome is not active for this subject and grade.' => ['outcome_id', 'Kazanım bu ders ve sınıf için uygun değil.'],
            'Subject is required.' => ['subject_id', 'Ders seçin.'],
            'Subject cannot change.' => ['subject_id', 'Ders değiştirilemez.'],
            'Explanation is invalid.' => ['explanation', 'Çözüm metni geçersiz.'],
            'explanation_markup' => ['explanation', 'Çözüm metninde HTML kullanılamaz.'],
            'explanation_math' => ['explanation', 'Çözüm formülü kabul edilmiyor.'],
            'Image target is invalid.' => ['question_image', 'Görselin yeri geçersiz.'],
            default => [null, QuestionFailureReason::ImageRejected === $exception->getReason()
                ? $this->imageMessage($exception)
                : 'Soru kaydedilemedi. Alanları kontrol edin.'],
        };
        if (null === $field && QuestionFailureReason::ImageRejected === $exception->getReason()) {
            $field = 'question_image';
        }

        return [
            'summary' => 'Soru kaydedilemedi. Alanları kontrol edin.',
            'fields' => null === $field ? [] : [$field => $message],
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function postedValues(Request $request, array $values): array
    {
        $options = [];
        foreach ($request->request->all('options') as $option) {
            if (!\is_string($option)) {
                continue;
            }
            $options[] = $this->safeText($option);
        }
        if (\count($options) < 2) {
            $options = array_pad($options, 2, '');
        }
        $correct = $request->request->getInt('correct');
        $fallbackCorrect = $values['correct'] ?? 1;
        if (!\is_int($fallbackCorrect)) {
            $fallbackCorrect = 1;
        }
        if ($correct < 1 || $correct > \count($options)) {
            $correct = $fallbackCorrect;
        }
        $difficulty = $request->request->getString('difficulty');
        $fallbackDifficulty = $values['difficulty'] ?? QuestionDifficulty::Medium->value;
        if (!\is_string($fallbackDifficulty)) {
            $fallbackDifficulty = QuestionDifficulty::Medium->value;
        }
        if (!\in_array($difficulty, [QuestionDifficulty::Easy->value, QuestionDifficulty::Medium->value, QuestionDifficulty::Hard->value], true)) {
            $difficulty = $fallbackDifficulty;
        }
        $outcomeId = strtolower($request->request->getString('outcome_id'));
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $outcomeId)) {
            $outcomeId = '';
        }
        $optionDocuments = [];
        foreach ($request->request->all('options_document') as $document) {
            $optionDocuments[] = \is_string($document) ? $this->restoredDocument($document) : '';
        }
        if (\count($optionDocuments) < \count($options)) {
            $optionDocuments = array_pad($optionDocuments, \count($options), '');
        }

        return [
            'stem' => $this->safeText($request->request->getString('stem')),
            'explanation' => $this->safeText($request->request->getString('explanation')),
            'options' => $options,
            'stem_document' => $this->restoredDocument($request->request->getString('stem_document')),
            'explanation_document' => $this->restoredDocument($request->request->getString('explanation_document')),
            'option_documents' => $optionDocuments,
            'correct' => $correct,
            'difficulty' => $difficulty,
            'outcome_id' => $outcomeId,
            'has_image' => true === ($values['has_image'] ?? false),
            'image_slot' => \in_array($request->request->getString('image_target'), ['stem', 'explanation'], true) || 1 === preg_match('/^option:[1-6]$/', $request->request->getString('image_target'))
                ? $request->request->getString('image_target')
                : (\is_string($values['image_slot'] ?? null) ? $values['image_slot'] : 'stem'),
        ];
    }

    private function restoredDocument(string $value): string
    {
        $value = trim($value);
        if ('' === $value || \strlen($value) > 30000 || $this->containsMarkup($value) || str_contains($value, 'image_reference') || str_contains($value, 'mediaId')) {
            return '';
        }
        try {
            $decoded = json_decode($value, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        return \is_array($decoded) ? $value : '';
    }

    /**
     * @param list<\App\Entity\QuestionRevisionOption> $optionRows
     *
     * @return list<list<array<string, mixed>>>
     */
    private function optionBlocks(array $optionRows, bool $imageNote): array
    {
        $blocks = [];
        foreach ($optionRows as $option) {
            $blocks[] = QuestionContentView::blocks($option->getContent(), $imageNote);
        }

        return $blocks;
    }

    /**
     * @return array{slot: string, block: array{type: string, mediaId: string}}|null
     */
    private function currentImageBlock(Question $question): ?array
    {
        $revision = $this->currentRevision($question);
        $documents = [
            'stem' => $revision->getStemContent(),
            'explanation' => $revision->getExplanationContent(),
        ];
        $index = 1;
        foreach ($this->options->findByRevision($revision) as $option) {
            $documents['option:'.$index] = $option->getContent();
            ++$index;
        }
        $found = null;
        foreach ($documents as $slot => $document) {
            $blocks = \is_array($document) ? ($document['blocks'] ?? null) : null;
            if (!\is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (!\is_array($block) || 'image_reference' !== ($block['type'] ?? null)) {
                    continue;
                }
                $mediaId = $block['mediaId'] ?? null;
                if (!\is_string($mediaId) || !Uuid::isValid($mediaId)) {
                    continue;
                }
                if (null !== $found) {
                    return null;
                }
                $found = [
                    'slot' => $slot,
                    'block' => ['type' => 'image_reference', 'mediaId' => $mediaId],
                ];
            }
        }

        return $found;
    }

    private function safeText(string $value): string
    {
        return $this->containsMarkup($value) ? '' : $value;
    }

    private function containsMarkup(string $value): bool
    {
        return 1 === preg_match('/[<>]/', $value) || str_contains(strtolower($value), 'javascript:');
    }

    private function editorDifficulty(QuestionDifficulty $difficulty): string
    {
        return match ($difficulty) {
            QuestionDifficulty::Easy, QuestionDifficulty::VeryEasy => QuestionDifficulty::Easy->value,
            QuestionDifficulty::Hard, QuestionDifficulty::VeryHard => QuestionDifficulty::Hard->value,
            QuestionDifficulty::Medium => QuestionDifficulty::Medium->value,
        };
    }

    private function difficultyLabel(QuestionDifficulty $difficulty): string
    {
        return match ($difficulty) {
            QuestionDifficulty::VeryEasy, QuestionDifficulty::Easy => 'Kolay',
            QuestionDifficulty::Medium => 'Orta',
            QuestionDifficulty::Hard, QuestionDifficulty::VeryHard => 'Zor',
        };
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            QuestionStatus::Draft->value => 'Taslak',
            QuestionStatus::InReview->value => 'İncelemede',
            QuestionStatus::Published->value => 'Yayında',
            QuestionStatus::Archived->value => 'Arşivlenmiş',
        ];
    }

    private function defaultReason(string $action): string
    {
        return match ($action) {
            'submit' => ContentWorkflowReason::SUBMIT_REVIEW,
            'return' => ContentWorkflowReason::RETURN_DRAFT,
            'publish' => ContentWorkflowReason::PUBLISH,
            'archive' => ContentWorkflowReason::ARCHIVE,
            default => ContentWorkflowReason::SUBMIT_REVIEW,
        };
    }

    private function successMessage(string $action): string
    {
        return match ($action) {
            'submit' => 'Soru incelemeye gönderildi.',
            'return' => 'Soru taslağa döndürüldü.',
            'publish' => 'Soru yayınlandı.',
            'archive' => 'Soru arşivlendi.',
            default => 'Kaydedildi.',
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectQuestionError(string $route, array $params, QuestionException $exception): Response
    {
        $this->addFlash('error', $this->questionMessage($exception));
        $response = $this->redirectToRoute($route, $params);
        if ($this->getParameter('kernel.debug')) {
            $response->headers->set('X-Question-Failure', $exception->getMessage());
        }

        return $response;
    }

    private function questionMessage(QuestionException $exception): string
    {
        return match ($exception->getReason()) {
            QuestionFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            QuestionFailureReason::InvalidTransition => 'Bu durumda bu işlem yapılamaz.',
            QuestionFailureReason::Conflict => 'Soru bu sırada başka bir işlemle değişti. Sayfayı yenileyip tekrar deneyin.',
            QuestionFailureReason::ReviewSeparation => 'Kendi hazırladığınız soruyu yayınlayamazsınız.',
            QuestionFailureReason::Immutable => 'Yayınlanmış sürüm değiştirilemez.',
            QuestionFailureReason::ImageRejected => $this->imageMessage($exception),
            default => 'Soru kaydedilemedi. Alanları kontrol edin.',
        };
    }

    private function imageMessage(QuestionException $exception): string
    {
        return match ($exception->getMessage()) {
            'question_image_exif' => 'Görsel EXIF bilgisi içeriyor.',
            'question_image_gif', 'question_image_svg', 'question_image_animation', 'question_image_type' => 'Görsel türü kabul edilmiyor.',
            'question_image_limit' => 'Görsel boyutu sınırı aşıyor.',
            'question_image_decode' => 'Görsel okunamadı.',
            'question_image_pending' => 'Bekleyen görsel incelenemez veya yayımlanamaz.',
            'question_image_scope' => 'Görsel bu soruya bağlanamaz.',
            default => 'Görsel kaydedilemedi.',
        };
    }

    private function uploadedBytes(Request $request): ?string
    {
        $file = $request->files->get('question_image');
        if (!$file instanceof UploadedFile) {
            return null;
        }
        if (!$file->isValid()) {
            throw QuestionException::imageRejected(\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'question_image_limit' : 'question_image_decode');
        }
        $bytes = file_get_contents($file->getPathname());
        if (!\is_string($bytes) || '' === $bytes) {
            throw QuestionException::imageRejected('question_image_decode');
        }

        return $bytes;
    }

    private function declaredMime(Request $request): ?string
    {
        $file = $request->files->get('question_image');

        return $file instanceof UploadedFile ? $file->getClientMimeType() : null;
    }
}
