<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionFailureReason;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SubjectRepository;
use App\Security\QuestionPermission;
use App\Service\InstitutionWorkspaceGate;
use App\Service\InstitutionWorkspaceQuery;
use App\Service\InvitationCodeDigestHasher;
use App\Service\QuestionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/kurum')]
#[IsGranted('ROLE_USER')]
final class InstitutionQuestionAuthoringController extends AbstractController
{
    public function __construct(
        private readonly InstitutionWorkspaceGate $gate,
        private readonly InstitutionWorkspaceQuery $query,
        private readonly QuestionManager $questions,
        private readonly QuestionRepository $questionRepository,
        private readonly QuestionRevisionRepository $revisions,
        private readonly QuestionRevisionOptionRepository $options,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly SubjectRepository $subjectRepository,
        private readonly InvitationCodeDigestHasher $hasher,
    ) {
    }

    #[Route('/sorular/yeni', name: 'app_institution_question_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $institution = $this->institution();
        $subjectRows = $this->subjectRepository->findActiveOrdered();
        $subjects = $this->subjectChoices($subjectRows);
        if (!$request->isMethod('POST')) {
            $grade = $this->selectedGrade($request);
            $subject = $this->selectedSubject($request, $subjectRows, $subjects);
            $outcomes = $subject instanceof Subject ? $this->query->publishedOutcomeChoices($subject, $grade) : [];

            return $this->form($grade, $subjects, $outcomes, null, $this->blankValues($subject, $grade));
        }
        if (!$this->isCsrfTokenValid('institution_question_create', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $grade = $this->postedGrade($request->request->getString('grade'));
        $subject = $this->postedSubject($request, $subjectRows);
        $values = $this->blankValues($subject, $grade ?? GradeLevel::Grade1);
        foreach (['stem', 'option_1', 'option_2', 'option_3', 'option_4', 'option_5', 'option_6', 'correct', 'outcome_ref', 'subject_ref', 'grade'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }
        if (!$subject instanceof Subject) {
            return $this->form($grade ?? GradeLevel::Grade1, $subjects, [], 'Ders geçerli olmalıdır.', $values);
        }
        if (!$grade instanceof GradeLevel) {
            return $this->form(GradeLevel::Grade1, $subjects, [], 'Sınıf geçerli olmalıdır.', $values);
        }
        $outcomes = $this->query->publishedOutcomeChoices($subject, $grade);
        if ([] === $outcomes) {
            return $this->form($grade, $subjects, [], 'Bu sınıf ve ders için kullanılabilir kazanım yok.', $values);
        }
        $outcome = $this->outcome($subject, $grade, $values['outcome_ref']);
        $optionTexts = $this->optionTexts($values);
        $correct = \is_array($optionTexts) ? $this->correctIndex($values['correct'], $optionTexts) : null;
        if ($this->hasMarkup($values['stem']) || '' === $values['stem'] || null === $correct || null === $optionTexts) {
            return $this->form($grade, $subjects, $outcomes, 'Soru metni ve seçenekler geçerli olmalıdır.', $values);
        }
        $specs = [];
        foreach ($optionTexts as $slot => $text) {
            $specs[] = [
                'stableKey' => 'opt_'.$slot,
                'content' => QuestionContentDocument::paragraph($text),
                'position' => $slot,
            ];
        }
        try {
            $question = $this->questions->createDraftQuestion(
                $this->account(),
                QuestionScope::Institution,
                $institution,
                $subject,
                $grade,
                QuestionType::SingleChoice,
                QuestionContentDocument::paragraph($values['stem']),
                null,
                $specs,
                ['correctStableKey' => 'opt_'.$correct],
                [['learningOutcome' => $outcome, 'isPrimary' => true]],
                QuestionDifficulty::Medium,
                'panel_question_create',
            );
        } catch (QuestionException $exception) {
            $message = $this->failureMessage($exception);
            if (null === $message) {
                throw new NotFoundHttpException('Not Found');
            }

            return $this->form($grade, $subjects, $outcomes, $message, $values);
        }

        return $this->redirectToRoute('app_institution_question_show', [
            'reference' => $this->hasher->workspaceReference('question', $question->getId()),
        ]);
    }

    #[Route('/sorular/{reference}', name: 'app_institution_question_show', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function show(string $reference): Response
    {
        return $this->render('institution/question.html.twig', $this->frame($this->detail($reference)));
    }

    #[Route('/sorular/{reference}/incelemeye-gonder', name: 'app_institution_question_submit', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function submit(Request $request, string $reference): Response
    {
        if (!$this->isCsrfTokenValid('institution_question_submit', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $question = $this->requireQuestion($reference);
        try {
            $this->questions->submitForReview($question, $this->account(), 'panel_question_submit');
        } catch (QuestionException $exception) {
            return $this->fail($reference, $exception);
        }
        $this->addFlash('success', 'Soru incelemeye gönderildi.');

        return $this->redirectToRoute('app_institution_question_show', ['reference' => strtolower($reference)]);
    }

    #[Route('/sorular/{reference}/yayinla', name: 'app_institution_question_publish', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function publish(Request $request, string $reference): Response
    {
        if (!$this->isCsrfTokenValid('institution_question_publish', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $question = $this->requireQuestion($reference);
        try {
            $this->questions->publish($question, $this->account(), 'panel_question_publish');
        } catch (QuestionException $exception) {
            return $this->fail($reference, $exception);
        }
        $this->addFlash('success', 'Soru yayımlandı.');

        return $this->redirectToRoute('app_institution_question_show', ['reference' => strtolower($reference)]);
    }

    /**
     * @param list<array{reference: string, name: string}> $subjects
     * @param list<\App\Dto\InstitutionOutcomeChoice>      $outcomes
     * @param array<string, string>                        $values
     */
    private function form(GradeLevel $grade, array $subjects, array $outcomes, ?string $error, array $values): Response
    {
        return $this->render('institution/question_create.html.twig', $this->frame([
            'error' => $error,
            'grades' => array_map(static fn (GradeLevel $item): int => $item->value, GradeLevel::cases()),
            'grade' => $grade->value,
            'subjects' => $subjects,
            'outcomes' => $outcomes,
            'values' => $values,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $reference): array
    {
        $question = $this->requireQuestion($reference);
        $revision = $this->revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        if (!$revision instanceof QuestionRevision || QuestionType::SingleChoice !== $revision->getType()) {
            throw new NotFoundHttpException('Not Found');
        }
        $showKey = $this->isGranted(QuestionPermission::ANSWER_KEY_VIEW, $question);
        $correctKey = $showKey ? $this->correctKey($revision) : null;
        $options = [];
        foreach ($this->options->findByRevision($revision) as $option) {
            $options[] = [
                'text' => self::blockText($option->getContent()),
                'correct' => null !== $correctKey && hash_equals($correctKey, $option->getStableKey()),
            ];
        }
        $authorId = $revision->getCreatedBy()->getId();

        return [
            'question' => [
                'reference' => strtolower($reference),
                'stem' => self::blockText($revision->getStemContent()),
                'subject_name' => $question->getSubject()->getName(),
                'grade' => $question->getGradeLevel()->value,
                'status_label' => self::statusLabel($question->getStatus()),
                'options' => $options,
            ],
            'can_submit' => QuestionStatus::Draft === $question->getStatus(),
            'can_publish' => QuestionStatus::InReview === $question->getStatus() && !$authorId->equals($this->account()->getId()),
            'show_answer' => $showKey,
        ];
    }

    private function fail(string $reference, QuestionException $exception): Response
    {
        $message = $this->failureMessage($exception);
        if (null === $message) {
            throw new NotFoundHttpException('Not Found');
        }
        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_institution_question_show', ['reference' => strtolower($reference)]);
    }

    private function failureMessage(QuestionException $exception): ?string
    {
        return match ($exception->getReason()) {
            QuestionFailureReason::ReviewSeparation => 'Kendi hazırladığınız soruyu yayımlayamazsınız.',
            QuestionFailureReason::InvalidTransition => 'Bu durum geçişi yapılamaz.',
            QuestionFailureReason::AnswerInvalid, QuestionFailureReason::ContentInvalid => 'Soru metni ve seçenekler geçerli olmalıdır.',
            QuestionFailureReason::AlignmentInvalid, QuestionFailureReason::CurriculumNotPublished => 'Kazanım bu sınıf ve ders için kullanılamaz.',
            QuestionFailureReason::Unauthorized, QuestionFailureReason::NotFound, QuestionFailureReason::ScopeMismatch => null,
            default => 'Soru kaydedilemedi.',
        };
    }

    private function requireQuestion(string $reference): Question
    {
        $id = $this->query->institutionQuestionId($this->institution(), $reference);
        $question = $id instanceof Uuid ? $this->questionRepository->findOneById($id) : null;
        if (!$question instanceof Question
            || QuestionScope::Institution !== $question->getScope()
            || !$question->getInstitution()?->getId()->equals($this->institution()->getId())
        ) {
            throw new NotFoundHttpException('Not Found');
        }

        return $question;
    }

    private function outcome(Subject $subject, GradeLevel $grade, string $reference): CurriculumLearningOutcome
    {
        $id = $this->query->publishedOutcomeId($subject, $grade, $reference);
        $outcome = $id instanceof Uuid ? $this->outcomes->findOneById($id) : null;
        if (!$outcome instanceof CurriculumLearningOutcome) {
            throw new NotFoundHttpException('Not Found');
        }

        return $outcome;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<int, string>|null
     */
    private function optionTexts(array $values): ?array
    {
        $texts = [];
        for ($index = 1; $index <= 6; ++$index) {
            $text = $values['option_'.$index] ?? '';
            if ('' === $text) {
                continue;
            }
            if ($this->hasMarkup($text)) {
                return null;
            }
            $texts[$index] = $text;
        }
        if (\count($texts) < 2) {
            return null;
        }

        return $texts;
    }

    /**
     * @param array<int, string> $texts
     */
    private function correctIndex(string $raw, array $texts): ?int
    {
        if (1 !== preg_match('/^[1-6]$/', $raw)) {
            return null;
        }
        $index = (int) $raw;

        return isset($texts[$index]) ? $index : null;
    }

    private function correctKey(QuestionRevision $revision): ?string
    {
        $key = $this->answerKeys->findOneByRevision($revision);
        $payload = $key?->getAnswerPayload() ?? [];
        $correct = $payload['correctStableKey'] ?? null;

        return \is_string($correct) && '' !== $correct ? $correct : null;
    }

    /**
     * @param list<Subject> $subjects
     *
     * @return list<array{reference: string, name: string}>
     */
    private function subjectChoices(array $subjects): array
    {
        $choices = [];
        foreach ($subjects as $subject) {
            $choices[] = [
                'reference' => $this->hasher->workspaceReference('subject', $subject->getId()),
                'name' => $subject->getName(),
            ];
        }

        return $choices;
    }

    /**
     * @param list<Subject>                                $subjects
     * @param list<array{reference: string, name: string}> $choices
     */
    private function selectedSubject(Request $request, array $subjects, array $choices): ?Subject
    {
        $raw = strtolower(trim($request->query->getString('ders')));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $raw)) {
            $raw = $choices[0]['reference'] ?? '';
        }

        return $this->matchSubject($subjects, $raw);
    }

    /**
     * @param list<Subject> $subjects
     */
    private function postedSubject(Request $request, array $subjects): ?Subject
    {
        $raw = strtolower(trim($request->request->getString('subject_ref')));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $raw)) {
            return null;
        }

        return $this->matchSubject($subjects, $raw);
    }

    /**
     * @param list<Subject> $subjects
     */
    private function matchSubject(array $subjects, string $reference): ?Subject
    {
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        foreach ($subjects as $subject) {
            if (hash_equals($this->hasher->workspaceReference('subject', $subject->getId()), $reference)) {
                return $subject;
            }
        }

        return null;
    }

    private function selectedGrade(Request $request): GradeLevel
    {
        return $this->exactGrade($request->query->getString('sinif')) ?? GradeLevel::Grade1;
    }

    private function postedGrade(string $raw): ?GradeLevel
    {
        return $this->exactGrade($raw);
    }

    private function exactGrade(string $raw): ?GradeLevel
    {
        if (1 !== preg_match('/^(?:[1-9]|1[0-2])$/', trim($raw))) {
            return null;
        }

        return GradeLevel::tryFrom((int) $raw);
    }

    /**
     * @return array<string, string>
     */
    private function blankValues(?Subject $subject, GradeLevel $grade): array
    {
        return [
            'stem' => '',
            'option_1' => '',
            'option_2' => '',
            'option_3' => '',
            'option_4' => '',
            'option_5' => '',
            'option_6' => '',
            'correct' => '1',
            'subject_ref' => $subject instanceof Subject ? $this->hasher->workspaceReference('subject', $subject->getId()) : '',
            'grade' => (string) $grade->value,
            'outcome_ref' => '',
        ];
    }

    private function hasMarkup(string $value): bool
    {
        return str_contains($value, '<') || str_contains($value, '>');
    }

    private function institution(): Institution
    {
        $user = $this->account();
        $decision = $this->gate->resolve($user);
        if (InstitutionWorkspaceGate::PANEL !== $decision->outcome || null === $decision->selected) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $decision->selected->getInstitution();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function frame(array $extra): array
    {
        $decision = $this->gate->resolve($this->account());
        $selected = $decision->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $extra + [
            'nav' => 'tests',
            'institution_name' => $selected->getInstitution()->getName(),
            'role_label' => InstitutionWorkspaceGate::roleLabel($selected->getRole()->value),
            'can_switch' => \count($decision->options) > 1,
            'options' => $decision->options,
        ];
    }

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function blockText(array $document): string
    {
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return '';
        }
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $text = $block['text'] ?? null;
            if (\is_string($text) && '' !== trim($text)) {
                return trim($text);
            }
        }

        return '';
    }

    private static function statusLabel(QuestionStatus $status): string
    {
        return match ($status) {
            QuestionStatus::Draft => 'Taslak',
            QuestionStatus::InReview => 'İncelemede',
            QuestionStatus::Published => 'Yayında',
            QuestionStatus::Archived => 'Arşiv',
        };
    }
}
