<?php

declare(strict_types=1);

namespace App\Controller;

use App\Assessment\AssessmentScore;
use App\Entity\Institution;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentFailureReason;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionOrderMode;
use App\Enum\ResultReleasePolicy;
use App\Exception\AssessmentException;
use App\Repository\SubjectRepository;
use App\Service\AssessmentManager;
use App\Service\InstitutionWorkspaceGate;
use App\Service\InstitutionWorkspaceQuery;
use App\Service\InvitationCodeDigestHasher;
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
final class InstitutionTestAuthoringController extends AbstractController
{
    public function __construct(
        private readonly InstitutionWorkspaceGate $gate,
        private readonly InstitutionWorkspaceQuery $query,
        private readonly AssessmentManager $assessments,
        private readonly SubjectRepository $subjects,
        private readonly InvitationCodeDigestHasher $hasher,
    ) {
    }

    #[Route('/testler/yeni', name: 'app_institution_test_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $institution = $this->institution();
        $grades = $this->query->selectableGrades($institution);
        $grade = $this->selectedGrade($request, $grades);
        $values = [
            'title' => '',
            'instructions' => '',
            'duration_minutes' => '',
            'points' => '1',
            'grade' => null === $grade ? '' : (string) $grade->value,
        ];
        if (!$request->isMethod('POST')) {
            return $this->form($institution, $grade, null, $values);
        }
        if (!$this->isCsrfTokenValid('institution_test_create', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $values = [
            'title' => trim($request->request->getString('title')),
            'instructions' => trim($request->request->getString('instructions')),
            'duration_minutes' => trim($request->request->getString('duration_minutes')),
            'points' => trim($request->request->getString('points')),
            'grade' => trim($request->request->getString('grade')),
        ];
        $grade = GradeLevel::tryFrom((int) $values['grade']);
        if (!$grade instanceof GradeLevel || !\in_array($grade->value, $grades, true)) {
            return $this->form($institution, null, 'Sınıf düzeyi geçerli olmalıdır.', $values);
        }
        if ($this->hasMarkup($values['title']) || $this->hasMarkup($values['instructions']) || '' === $values['title']) {
            return $this->form($institution, $grade, 'Test adı geçerli olmalıdır.', $values);
        }
        try {
            $duration = $this->durationSeconds($values['duration_minutes']);
            $points = AssessmentScore::normalizePoints('' === $values['points'] ? '1' : $values['points']);
            $resolved = $this->resolveItems($institution, $grade, $request->request->all('question_refs'), $points);
            if ('empty' === $resolved) {
                return $this->form($institution, $grade, 'Henüz kullanılabilir yayımlı kurum sorusu yok.', $values);
            }
            if ('none' === $resolved) {
                return $this->form($institution, $grade, 'En az bir soru seçin.', $values);
            }
            $assessment = $this->assessments->createDraftAssessment(
                $this->account(),
                AssessmentScope::Institution,
                $institution,
                AssessmentType::Quiz,
                $grade,
                $values['title'],
                null,
                '' === $values['instructions'] ? null : $values['instructions'],
                $duration,
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::Immediate,
                null,
                [[
                    'title' => 'Sorular',
                    'position' => 1,
                    'questionOrderMode' => QuestionOrderMode::Fixed,
                    'items' => $resolved['items'],
                ]],
                'panel_test_create',
                $resolved['subject'],
            );
        } catch (AssessmentException $exception) {
            $message = $this->failureMessage($exception);
            if (null === $message) {
                throw new NotFoundHttpException('Not Found');
            }

            return $this->form($institution, $grade, $message, $values);
        }

        return $this->redirectToRoute('app_institution_test', [
            'reference' => $this->hasher->workspaceReference('assessment', $assessment->getId()),
        ]);
    }

    #[Route('/testler/{reference}/incelemeye-gonder', name: 'app_institution_test_submit', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function submit(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_test_submit', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $assessment = $this->query->institutionAssessment($institution, strtolower($reference));
        if (null === $assessment) {
            throw new NotFoundHttpException('Not Found');
        }
        try {
            $this->assessments->submitForReview($assessment, $this->account(), 'panel_test_submit');
        } catch (AssessmentException $exception) {
            $message = $this->failureMessage($exception);
            if (null === $message) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $message);

            return $this->redirectToRoute('app_institution_test', ['reference' => strtolower($reference)]);
        }

        return $this->redirectToRoute('app_institution_test', ['reference' => strtolower($reference)]);
    }

    #[Route('/testler/{reference}/yayinla', name: 'app_institution_test_publish', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function publish(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_test_publish', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $assessment = $this->query->institutionAssessment($institution, strtolower($reference));
        if (null === $assessment) {
            throw new NotFoundHttpException('Not Found');
        }
        try {
            $this->assessments->publish($assessment, $this->account(), 'panel_test_publish');
        } catch (AssessmentException $exception) {
            $message = $this->failureMessage($exception);
            if (null === $message) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $message);

            return $this->redirectToRoute('app_institution_test', ['reference' => strtolower($reference)]);
        }

        return $this->redirectToRoute('app_institution_test', ['reference' => strtolower($reference)]);
    }

    /**
     * @param array{title: string, instructions: string, duration_minutes: string, points: string, grade: string} $values
     */
    private function form(Institution $institution, ?GradeLevel $grade, ?string $error, array $values): Response
    {
        $questions = $grade instanceof GradeLevel ? $this->query->selectableQuestions($institution, $grade) : [];

        return $this->render('institution/test_create.html.twig', $this->frame([
            'grades' => $this->query->selectableGrades($institution),
            'questions' => $questions,
            'error' => $error,
            'values' => $values,
        ]));
    }

    /**
     * @param list<int> $grades
     */
    private function selectedGrade(Request $request, array $grades): ?GradeLevel
    {
        if ([] === $grades) {
            return null;
        }
        $requested = GradeLevel::tryFrom($request->query->getInt('sinif'));
        if ($requested instanceof GradeLevel && \in_array($requested->value, $grades, true)) {
            return $requested;
        }
        $first = GradeLevel::tryFrom($grades[0]);

        return $first instanceof GradeLevel ? $first : null;
    }

    private function durationSeconds(string $minutes): ?int
    {
        if ('' === $minutes) {
            return null;
        }
        if (1 !== preg_match('/^\d+$/', $minutes)) {
            throw AssessmentException::invalidInput('duration rejected');
        }
        $value = (int) $minutes;
        if ($value < 1 || $value > 360) {
            throw AssessmentException::invalidInput('duration rejected');
        }

        return $value * 60;
    }

    /**
     * @return array{subject: Subject, items: list<array{questionId: string, questionRevisionId: string, position: int, points: string, penaltyPoints: string, required: bool}>}|'empty'|'none'
     */
    private function resolveItems(Institution $institution, GradeLevel $grade, mixed $references, string $points): array|string
    {
        if (!\is_array($references) || [] === $references) {
            return 'none';
        }
        $resolved = $this->query->resolveSelectableQuestions($institution, $grade, $references);
        if ($resolved->catalogEmpty) {
            return 'empty';
        }
        if ($resolved->rejected || [] === $resolved->items) {
            throw new NotFoundHttpException('Not Found');
        }
        $subjectId = null;
        foreach ($resolved->items as $selection) {
            if (null !== $subjectId && $subjectId !== $selection->subjectId) {
                throw AssessmentException::subjectMismatch();
            }
            $subjectId = $selection->subjectId;
        }
        $subject = $this->subjects->findOneById(Uuid::fromString($subjectId));
        if (!$subject instanceof Subject) {
            throw new NotFoundHttpException('Not Found');
        }
        $items = [];
        $position = 1;
        foreach ($resolved->items as $selection) {
            $items[] = [
                'questionId' => $selection->questionId,
                'questionRevisionId' => $selection->revisionId,
                'position' => $position,
                'points' => $points,
                'penaltyPoints' => '0',
                'required' => true,
            ];
            ++$position;
        }

        return ['subject' => $subject, 'items' => $items];
    }

    private function failureMessage(AssessmentException $exception): ?string
    {
        return match ($exception->getReason()) {
            AssessmentFailureReason::ReviewSeparation => 'Kendi hazırladığınız testi yayımlayamazsınız.',
            AssessmentFailureReason::InvalidPoints, AssessmentFailureReason::InvalidInput => 'Süre veya puan geçerli olmalıdır.',
            AssessmentFailureReason::SubjectMismatch => 'Seçilen sorular aynı dersten olmalıdır.',
            AssessmentFailureReason::GradeMismatch => 'Seçilen sorular testin sınıf düzeyiyle uyuşmuyor.',
            AssessmentFailureReason::InvalidTransition => 'Bu durum geçişi yapılamaz.',
            AssessmentFailureReason::EmptySection, AssessmentFailureReason::EmptyAssessment => 'En az bir soru seçin.',
            AssessmentFailureReason::Unauthorized, AssessmentFailureReason::NotFound, AssessmentFailureReason::ScopeMismatch, AssessmentFailureReason::QuestionScopeMismatch, AssessmentFailureReason::QuestionNotPublished, AssessmentFailureReason::QuestionRevisionMismatch => null,
            default => 'Test kaydedilemedi.',
        };
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
}
