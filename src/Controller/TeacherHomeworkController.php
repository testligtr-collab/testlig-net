<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\HomeworkDraftInput;
use App\Dto\HomeworkTeacherSubmissionDetail;
use App\Entity\HomeworkDefinition;
use App\Entity\HomeworkSubmission;
use App\Entity\User;
use App\Exception\HomeworkDefinitionException;
use App\Exception\HomeworkEvaluationException;
use App\Repository\HomeworkSubmissionRepository;
use App\Security\HomeworkDefinitionPermission;
use App\Security\HomeworkEvaluationPermission;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Homework\HomeworkAssignmentManager;
use App\Service\Homework\HomeworkDefinitionManager;
use App\Service\Homework\HomeworkDefinitionQuery;
use App\Service\Homework\HomeworkEvaluationManager;
use App\Service\Homework\HomeworkTeacherScope;
use App\Service\Homework\HomeworkTeacherSubmissionQuery;
use App\Service\Homework\HomeworkText;
use App\Time\UtcInstant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher homework: drafts, classroom assign with recipient snapshot, submitted-work review and
 * append-only evaluation (label + optional feedback). Evaluation never grants a resubmit.
 */
#[Route('/ogretmen/odevler')]
#[IsGranted('ROLE_USER')]
final class TeacherHomeworkController extends AbstractController
{
    private const CSRF_DRAFT = 'teacher_homework_draft';
    private const CSRF_ASSIGN = 'teacher_homework_assign';
    private const CSRF_EVALUATE = 'teacher_homework_evaluate';
    private const ID = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function __construct(
        private readonly HomeworkDefinitionManager $manager,
        private readonly HomeworkAssignmentManager $assignments,
        private readonly HomeworkDefinitionQuery $query,
        private readonly HomeworkTeacherScope $scope,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly HomeworkTeacherSubmissionQuery $submissionQuery,
        private readonly HomeworkEvaluationManager $evaluations,
        private readonly HomeworkSubmissionRepository $submissions,
    ) {
    }

    #[Route('', name: 'app_teacher_homeworks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $this->account();
        $classrooms = $this->scope->classrooms($actor, false);
        if ([] === $classrooms) {
            throw new NotFoundHttpException('Not Found');
        }
        $search = $request->query->getString('q');
        $filter = $this->optionalUuid($request->query->getString('sinif'));
        $known = false;
        foreach ($classrooms as $option) {
            if (null !== $filter && $option->id === $filter->toRfc4122()) {
                $known = true;
            }
        }
        if (!$known) {
            $filter = null;
        }
        $statusFilter = $request->query->getString('durum');
        $assignedOnly = match ($statusFilter) {
            'taslak' => false,
            'atandi' => true,
            default => null,
        };
        $page = $this->query->page($actor, $search, $filter, $request->query->getInt('sayfa', 1), $assignedOnly);
        $filtered = '' !== trim($search) || null !== $filter || null !== $assignedOnly;

        return $this->noStore($this->render('teacher/homework/index.html.twig', [
            'page' => $page,
            'classrooms' => $classrooms,
            'with_institution' => HomeworkTeacherScope::spansInstitutions($classrooms),
            'search' => trim($search),
            'filter' => $filter?->toRfc4122() ?? '',
            'status_filter' => $statusFilter,
            'filtered' => $filtered,
        ]));
    }

    #[Route('/yeni', name: 'app_teacher_homework_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $actor = $this->account();
        $options = $this->scope->classrooms($actor, true);
        if ([] === $this->scope->classrooms($actor, false)) {
            throw new NotFoundHttpException('Not Found');
        }
        $values = $this->emptyValues();
        $errors = [];
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, self::CSRF_DRAFT);
            $values = $this->postedValues($request);
            try {
                $definition = $this->manager->createDraft($actor, $this->inputFrom($values, null));
                $this->addFlash('success', 'Ödev taslağı kaydedildi. Öğrencilere atama yapılmadı.');

                return $this->redirectToRoute('app_teacher_homework_show', ['id' => $definition->getId()->toRfc4122()]);
            } catch (HomeworkDefinitionException $exception) {
                [$errors, $status] = $this->handleFailure($exception);
            }
        }

        return $this->noStore($this->renderForm('new', $options, $values, $errors, null), $status);
    }

    #[Route('/{id}', name: 'app_teacher_homework_show', methods: ['GET', 'POST'], requirements: ['id' => self::ID])]
    public function show(Request $request, string $id): Response
    {
        $actor = $this->account();
        $definitionId = Uuid::fromString($id);
        $definition = $this->query->findOwned($actor, $definitionId, false);
        if (!$definition instanceof HomeworkDefinition || !$this->isGranted(HomeworkDefinitionPermission::VIEW, $definition)) {
            throw new NotFoundHttpException('Not Found');
        }
        $assignment = $this->query->findAssignment($actor, $definitionId);
        $canAssign = null === $assignment && $this->isGranted(HomeworkDefinitionPermission::ASSIGN, $definition);
        $canEdit = null === $assignment && $this->isGranted(HomeworkDefinitionPermission::EDIT, $definition);
        $errors = [];
        $status = Response::HTTP_OK;
        $showConfirm = $request->query->getBoolean('ata');
        $eligibleCount = $canAssign ? $this->query->countEligibleRecipients($definition->getClassroom()->getId()) : 0;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, self::CSRF_ASSIGN);
            if (!$canAssign) {
                throw new NotFoundHttpException('Not Found');
            }
            $showConfirm = true;
            $confirmed = $request->request->getBoolean('confirm_assign');
            $revision = $this->revisionFrom(['revision' => $request->request->getString('revision')]);
            try {
                $this->assignments->assignToClassroom($actor, $definitionId, $revision ?? 0, $confirmed);
                $this->addFlash('success', 'Ödev sınıfa atandı. Ödev metni donduruldu.');

                return $this->redirectToRoute('app_teacher_homework_show', ['id' => $id]);
            } catch (HomeworkDefinitionException $exception) {
                [$errors, $status] = $this->handleAssignFailure($exception);
                $eligibleCount = $this->query->countEligibleRecipients($definition->getClassroom()->getId());
            }
        }

        $stats = null;
        $recipientRows = [];
        if (null !== $assignment) {
            $stats = $this->submissionQuery->statsFor($actor, $assignment);
            $recipientRows = $this->submissionQuery->recipientRows($actor, $assignment) ?? [];
        }

        return $this->noStore($this->render('teacher/homework/show.html.twig', [
            'definition' => $definition,
            'assignment' => $assignment,
            'stats' => $stats,
            'recipient_rows' => $recipientRows,
            'can_edit' => $canEdit,
            'can_assign' => $canAssign,
            'show_confirm' => $showConfirm && $canAssign,
            'eligible_count' => $eligibleCount,
            'errors' => $errors,
            'csrf_assign' => self::CSRF_ASSIGN,
        ]), $status);
    }

    #[Route('/teslim/{id}', name: 'app_teacher_homework_submission_show', methods: ['GET'], requirements: ['id' => self::ID])]
    public function submissionShow(string $id): Response
    {
        $actor = $this->account();
        $submission = $this->viewableSubmission(Uuid::fromString($id));
        $detail = $this->submissionQuery->submittedDetail($actor, $submission->getId());
        if (!$detail instanceof HomeworkTeacherSubmissionDetail) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->noStore($this->renderSubmission($detail, $this->evaluationValuesFrom($detail), []));
    }

    #[Route('/teslim/{id}/degerlendir', name: 'app_teacher_homework_submission_evaluate', methods: ['POST'], requirements: ['id' => self::ID])]
    public function submissionEvaluate(Request $request, string $id): Response
    {
        $actor = $this->account();
        $this->assertCsrf($request, self::CSRF_EVALUATE);
        $submissionId = Uuid::fromString($id);
        $submission = $this->viewableSubmission($submissionId);
        if (!$this->isGranted(HomeworkEvaluationPermission::EVALUATE, $submission)) {
            throw new NotFoundHttpException('Not Found');
        }

        $values = $this->postedEvaluationValues($request);
        $expected = null;
        if ('' !== $values['expected_current_evaluation_id']) {
            if (!Uuid::isValid($values['expected_current_evaluation_id'])) {
                throw new BadRequestHttpException('Geçersiz istek.');
            }
            $expected = Uuid::fromString($values['expected_current_evaluation_id']);
        }

        try {
            $this->evaluations->record($actor, $submissionId, $values['label'], $values['feedback'], $expected);
            $this->addFlash('success', 'Değerlendirme kaydedildi. Bu değerlendirme yeniden teslim hakkı vermez.');

            return $this->redirectToRoute('app_teacher_homework_submission_show', ['id' => $id]);
        } catch (HomeworkEvaluationException $exception) {
            $errors = [];
            $status = Response::HTTP_CONFLICT;
            switch ($exception->getReason()) {
                case HomeworkEvaluationException::REASON_INVALID_INPUT:
                    $errors = $exception->getFieldErrors();
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    break;
                case HomeworkEvaluationException::REASON_STALE:
                case HomeworkEvaluationException::REASON_NOT_SUBMITTED:
                case HomeworkEvaluationException::REASON_CONFLICT:
                    $this->addFlash('error', $exception->getMessage());
                    break;
                default:
                    throw new NotFoundHttpException('Not Found');
            }
        }

        $detail = $this->submissionQuery->submittedDetail($actor, $submissionId);
        if (!$detail instanceof HomeworkTeacherSubmissionDetail) {
            throw new NotFoundHttpException('Not Found');
        }
        // A stale post must carry the fresh current evaluation id so a deliberate resubmit can succeed.
        $values['expected_current_evaluation_id'] = $detail->currentEvaluationId ?? '';

        return $this->noStore($this->renderSubmission($detail, $values, $errors), $status);
    }

    #[Route('/{id}/duzenle', name: 'app_teacher_homework_edit', methods: ['GET', 'POST'], requirements: ['id' => self::ID])]
    public function edit(Request $request, string $id): Response
    {
        $actor = $this->account();
        $definitionId = Uuid::fromString($id);
        $definition = $this->query->findOwned($actor, $definitionId, true);
        if (!$definition instanceof HomeworkDefinition || !$this->isGranted(HomeworkDefinitionPermission::EDIT, $definition)) {
            throw new NotFoundHttpException('Not Found');
        }
        $options = $this->scope->classrooms($actor, true);
        $values = $this->valuesFrom($definition);
        $errors = [];
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, self::CSRF_DRAFT);
            $values = $this->postedValues($request);
            try {
                $this->manager->updateDraft($actor, $definitionId, $this->inputFrom($values, $this->revisionFrom($values)));
                $this->addFlash('success', 'Ödev taslağı güncellendi. Öğrencilere atama yapılmadı.');

                return $this->redirectToRoute('app_teacher_homework_show', ['id' => $id]);
            } catch (HomeworkDefinitionException $exception) {
                [$errors, $status] = $this->handleFailure($exception);
            }
        }

        return $this->noStore($this->renderForm('edit', $options, $values, $errors, $definition), $status);
    }

    /**
     * Opaque 404 when the submission is missing or not viewable by this teacher.
     */
    private function viewableSubmission(Uuid $submissionId): HomeworkSubmission
    {
        $submission = $this->submissions->find($submissionId);
        if (!$submission instanceof HomeworkSubmission
            || !$this->isGranted(HomeworkEvaluationPermission::VIEW_SUBMITTED, $submission)
        ) {
            throw new NotFoundHttpException('Not Found');
        }

        return $submission;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderSubmission(HomeworkTeacherSubmissionDetail $detail, array $values, array $errors): Response
    {
        return $this->render('teacher/homework/submission.html.twig', [
            'detail' => $detail,
            'values' => $values,
            'errors' => $errors,
            'feedback_max' => HomeworkText::FEEDBACK_MAX,
            'csrf_evaluate' => self::CSRF_EVALUATE,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function evaluationValuesFrom(HomeworkTeacherSubmissionDetail $detail): array
    {
        return [
            'label' => $detail->evaluationLabelKey ?? '',
            'feedback' => $detail->feedback ?? '',
            'expected_current_evaluation_id' => $detail->currentEvaluationId ?? '',
        ];
    }

    /**
     * @return array{label: string, feedback: string, expected_current_evaluation_id: string}
     */
    private function postedEvaluationValues(Request $request): array
    {
        $all = $request->request->all();
        $read = static function (string $key) use ($all): string {
            $raw = $all[$key] ?? '';

            return \is_string($raw) ? str_replace(["\r\n", "\r"], "\n", $raw) : '';
        };

        return [
            'label' => trim($read('label')),
            'feedback' => $read('feedback'),
            'expected_current_evaluation_id' => trim($read('expected_current_evaluation_id')),
        ];
    }

    /**
     * @param list<\App\Dto\HomeworkClassroomOption> $options
     * @param array<string, string>                  $values
     * @param array<string, string>                  $errors
     */
    private function renderForm(string $mode, array $options, array $values, array $errors, ?HomeworkDefinition $definition): Response
    {
        return $this->render('teacher/homework/form.html.twig', [
            'mode' => $mode,
            'classrooms' => $options,
            'with_institution' => HomeworkTeacherScope::spansInstitutions($options),
            'values' => $values,
            'errors' => $errors,
            'definition' => $definition,
            'title_max' => HomeworkText::TITLE_MAX,
            'description_max' => HomeworkText::DESCRIPTION_MAX,
            'csrf_intent' => self::CSRF_DRAFT,
            'cancel_route' => null === $definition ? 'app_teacher_homeworks' : 'app_teacher_homework_show',
        ]);
    }

    /**
     * @return array{0: array<string, string>, 1: int}
     */
    private function handleFailure(HomeworkDefinitionException $exception): array
    {
        return match ($exception->getReason()) {
            HomeworkDefinitionException::REASON_INVALID_INPUT => [
                $exception->getFieldErrors(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ],
            HomeworkDefinitionException::REASON_STALE_REVISION,
            HomeworkDefinitionException::REASON_CONFLICT,
            HomeworkDefinitionException::REASON_NOT_EDITABLE,
            HomeworkDefinitionException::REASON_ALREADY_ASSIGNED => $this->flashError($exception->getMessage()),
            default => throw new NotFoundHttpException('Not Found'),
        };
    }

    /**
     * @return array{0: array<string, string>, 1: int}
     */
    private function handleAssignFailure(HomeworkDefinitionException $exception): array
    {
        return match ($exception->getReason()) {
            HomeworkDefinitionException::REASON_NO_RECIPIENTS,
            HomeworkDefinitionException::REASON_DUE_NOT_FUTURE,
            HomeworkDefinitionException::REASON_CONFIRMATION_REQUIRED,
            HomeworkDefinitionException::REASON_INVALID_INPUT => [
                $exception->getFieldErrors() ?: ['form' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ],
            HomeworkDefinitionException::REASON_STALE_REVISION,
            HomeworkDefinitionException::REASON_CONFLICT,
            HomeworkDefinitionException::REASON_ALREADY_ASSIGNED => $this->flashError($exception->getMessage()),
            default => throw new NotFoundHttpException('Not Found'),
        };
    }

    /**
     * @return array{0: array<string, string>, 1: int}
     */
    private function flashError(string $message): array
    {
        $this->addFlash('error', $message);

        return [[], Response::HTTP_CONFLICT];
    }

    /**
     * @return array<string, string>
     */
    private function emptyValues(): array
    {
        return ['title' => '', 'description' => '', 'classroom' => '', 'due_date' => '', 'due_time' => '', 'revision' => ''];
    }

    /**
     * @return array<string, string>
     */
    private function valuesFrom(HomeworkDefinition $definition): array
    {
        $due = UtcInstant::forPresentation($definition->getDueAt(), HomeworkDefinitionManager::FORM_TIMEZONE);

        return [
            'title' => $definition->getTitle(),
            'description' => $definition->getDescription(),
            'classroom' => $definition->getClassroom()->getId()->toRfc4122(),
            'due_date' => $due->format('Y-m-d'),
            'due_time' => $due->format('H:i'),
            'revision' => (string) $definition->getRevisionNumber(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function postedValues(Request $request): array
    {
        $values = [];
        foreach (array_keys($this->emptyValues()) as $key) {
            $raw = $request->request->all()[$key] ?? '';
            $values[$key] = \is_string($raw) ? str_replace(["\r\n", "\r"], "\n", $raw) : '';
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private function inputFrom(array $values, ?int $revision): HomeworkDraftInput
    {
        return new HomeworkDraftInput(
            $values['title'],
            $values['description'],
            $values['classroom'],
            $values['due_date'],
            $values['due_time'],
            $revision,
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function revisionFrom(array $values): ?int
    {
        return 1 === preg_match('/^[1-9]\d{0,9}$/', $values['revision']) ? (int) $values['revision'] : null;
    }

    private function optionalUuid(string $raw): ?Uuid
    {
        $raw = trim($raw);

        return '' !== $raw && Uuid::isValid($raw) ? Uuid::fromString($raw) : null;
    }

    private function assertCsrf(Request $request, string $intent): void
    {
        if (!$this->isCsrfTokenValid($intent, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
    }

    private function noStore(Response $response, ?int $status = null): Response
    {
        if (null !== $status) {
            $response->setStatusCode($status);
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->activeUsers->isActiveAndVerified($user)) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $user;
    }
}
