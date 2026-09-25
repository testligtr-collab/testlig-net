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
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\QuestionSingleChoiceForm;
use App\Service\QuestionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/sorular', name: 'app_admin_questions', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function index(): Response
    {
        $actor = $this->requireActorUser();

        return $this->renderAdmin('admin/questions/list.html.twig', [
            'questions' => $this->questions->findPlatformVisible($actor, $this->canSeeAll($actor)),
            'can_author' => $this->adminAuthorization->canAuthorQuestions($actor),
            'status_labels' => $this->statusLabels(),
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

    #[Route('/yonetim/sorular/{id}', name: 'app_admin_question_show', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function show(string $id): Response
    {
        $question = $this->visibleQuestion($id);
        $revision = $this->currentRevision($question);
        $optionRows = $this->options->findByRevision($revision);
        $canAnswer = $this->isGranted(QuestionPermission::ANSWER_KEY_VIEW, $question);
        $actor = $this->requireActorUser();
        $isAuthor = $revision->getCreatedBy()->getId()->equals($actor->getId());

        return $this->renderAdmin('admin/questions/detail.html.twig', [
            'question' => $question,
            'revision_number' => $revision->getRevisionNumber(),
            'stem_lines' => $this->plainLines($revision->getStemContent()),
            'explanation_lines' => $this->plainLines($revision->getExplanationContent()),
            'option_lines' => $this->optionLines($optionRows),
            'correct_position' => $canAnswer ? $this->correctPosition($revision, $optionRows) : null,
            'status_label' => $this->statusLabels()[$question->getStatus()->value] ?? '',
            'difficulty_label' => $this->difficultyLabel($revision->getDifficulty()),
            'grade_label' => $question->getGradeLevel()->value.'. sınıf',
            'subject_name' => $question->getSubject()->getName(),
            'outcome_label' => $this->outcomeLabel($revision),
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
        ]);
    }

    #[Route('/yonetim/sorular/{id}/duzenle', name: 'app_admin_question_edit', methods: ['GET', 'POST'])]
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

    #[Route('/yonetim/sorular/{id}/onizleme', name: 'app_admin_question_preview', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function preview(string $id): Response
    {
        $question = $this->visibleQuestion($id);
        $revision = $this->currentRevision($question);

        return $this->renderAdmin('admin/questions/preview.html.twig', [
            'question' => $question,
            'stem_lines' => $this->plainLines($revision->getStemContent()),
            'explanation_lines' => $this->plainLines($revision->getExplanationContent()),
            'option_lines' => $this->optionLines($this->options->findByRevision($revision)),
        ]);
    }

    #[Route('/yonetim/sorular/{id}/incelemeye-gonder', name: 'app_admin_question_submit', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function submit(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'submit');
    }

    #[Route('/yonetim/sorular/{id}/taslaga-dondur', name: 'app_admin_question_return', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function returnToDraft(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'return');
    }

    #[Route('/yonetim/sorular/{id}/yayinla', name: 'app_admin_question_publish', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function publish(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'publish');
    }

    #[Route('/yonetim/sorular/{id}/arsivle', name: 'app_admin_question_archive', methods: ['POST'])]
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
            $question = $this->questionManager->createDraftQuestion(
                $actor,
                QuestionScope::Platform,
                null,
                $subject,
                $parsed['grade'],
                QuestionType::SingleChoice,
                $parsed['stem'],
                $parsed['explanation'],
                $parsed['options'],
                $parsed['answerSpec'],
                [['learningOutcome' => $outcome, 'isPrimary' => true]],
                $parsed['difficulty'],
                'question_draft_saved',
            );
            $this->addFlash('success', 'Taslak kaydedildi.');

            return $this->redirectToRoute('app_admin_question_show', ['id' => $question->getId()->toRfc4122()]);
        } catch (QuestionException $e) {
            $this->addFlash('error', $this->questionMessage($e));

            return $this->redirectToRoute('app_admin_question_new', [
                'subject_id' => $request->request->getString('subject_id'),
                'grade' => $request->request->getString('grade'),
            ]);
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
            $this->questionManager->createRevision(
                $question,
                $actor,
                QuestionType::SingleChoice,
                $parsed['stem'],
                $parsed['explanation'],
                $parsed['options'],
                $parsed['answerSpec'],
                [['learningOutcome' => $outcome, 'isPrimary' => true]],
                $parsed['difficulty'],
                'question_revision_saved',
            );
            $this->addFlash('success', 'Taslak kaydedildi.');
        } catch (QuestionException $e) {
            $this->addFlash('error', $this->questionMessage($e));

            return $this->redirectToRoute('app_admin_question_edit', ['id' => $question->getId()->toRfc4122()]);
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
            $this->addFlash('error', $this->questionMessage($e));
        } catch (LearningContentException) {
            $this->addFlash('error', 'İşlem notu kaydedilemedi.');
        }

        return $this->redirectToRoute('app_admin_question_show', ['id' => $question->getId()->toRfc4122()]);
    }

    private function renderForm(Request $request, ?Question $question): Response
    {
        $subjects = $this->subjects->findActiveOrdered();
        $subject = $this->selectedSubject($request, $subjects, $question);
        $grade = $this->selectedGrade($request, $question);
        $values = $this->formValues($question);
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
            'expected_revision' => $question?->getCurrentRevisionNumber() ?? 0,
        ]);
    }

    private function visibleQuestion(string $id): Question
    {
        try {
            $questionId = \Symfony\Component\Uid\Uuid::fromString($id);
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
            $subjectId = \Symfony\Component\Uid\Uuid::fromString($id);
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
            $outcomeId = \Symfony\Component\Uid\Uuid::fromString($id);
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
     * @return array{stem: string, explanation: string, options: list<string>, correct: int, difficulty: string, outcome_id: string}
     */
    private function formValues(?Question $question): array
    {
        $blank = [
            'stem' => '',
            'explanation' => '',
            'options' => ['', ''],
            'correct' => 1,
            'difficulty' => QuestionDifficulty::Medium->value,
            'outcome_id' => '',
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
        foreach ($optionRows as $option) {
            $lines = $this->plainLines($option->getContent());
            $options[] = $lines[0] ?? '';
        }
        if (\count($options) < 2) {
            $options = ['', ''];
        }
        $primary = '';
        foreach ($this->alignments->findByRevision($revision) as $alignment) {
            if ($alignment->isPrimary()) {
                $primary = $alignment->getLearningOutcome()->getId()->toRfc4122();
            }
        }
        $explanation = $this->plainLines($revision->getExplanationContent());

        return [
            'stem' => $this->plainLines($revision->getStemContent())[0] ?? '',
            'explanation' => $explanation[0] ?? '',
            'options' => $options,
            'correct' => $this->isGranted(QuestionPermission::ANSWER_KEY_VIEW, $question)
                ? ($this->correctPosition($revision, $optionRows) ?? 1)
                : 1,
            'difficulty' => $this->editorDifficulty($revision->getDifficulty()),
            'outcome_id' => $primary,
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
        if (null === $document) {
            return [];
        }
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return [];
        }
        $lines = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $text = $block['text'] ?? null;
            if (\is_string($text) && '' !== trim($text)) {
                $lines[] = $text;
            }
            $items = $block['items'] ?? null;
            if (\is_array($items)) {
                foreach ($items as $item) {
                    if (\is_string($item) && '' !== trim($item)) {
                        $lines[] = $item;
                    }
                }
            }
        }

        return $lines;
    }

    private function outcomeLabel(QuestionRevision $revision): string
    {
        foreach ($this->alignments->findByRevision($revision) as $alignment) {
            if ($alignment->isPrimary()) {
                $outcome = $alignment->getLearningOutcome();

                return $outcome->getCode().' — '.$outcome->getDescription();
            }
        }

        return '';
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

    private function questionMessage(QuestionException $exception): string
    {
        return match ($exception->getReason()) {
            QuestionFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            QuestionFailureReason::InvalidTransition => 'Bu durumda bu işlem yapılamaz.',
            QuestionFailureReason::Conflict => 'Soru bu sırada başka bir işlemle değişti. Sayfayı yenileyip tekrar deneyin.',
            QuestionFailureReason::ReviewSeparation => 'Kendi hazırladığınız soruyu yayınlayamazsınız.',
            QuestionFailureReason::Immutable => 'Yayınlanmış sürüm değiştirilemez.',
            default => 'Soru kaydedilemedi. Alanları kontrol edin.',
        };
    }
}
