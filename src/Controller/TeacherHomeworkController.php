<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\HomeworkDraftInput;
use App\Entity\HomeworkDefinition;
use App\Entity\User;
use App\Exception\HomeworkDefinitionException;
use App\Security\HomeworkDefinitionPermission;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Homework\HomeworkAssignmentManager;
use App\Service\Homework\HomeworkDefinitionManager;
use App\Service\Homework\HomeworkDefinitionQuery;
use App\Service\Homework\HomeworkTeacherScope;
use App\Service\Homework\HomeworkText;
use App\Time\UtcInstant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher homework: drafts, classroom assign with recipient snapshot. No student submit/evaluate.
 */
#[Route('/ogretmen/odevler')]
#[IsGranted('ROLE_USER')]
final class TeacherHomeworkController extends AbstractController
{
    private const CSRF_DRAFT = 'teacher_homework_draft';
    private const CSRF_ASSIGN = 'teacher_homework_assign';
    private const ID = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function __construct(
        private readonly HomeworkDefinitionManager $manager,
        private readonly HomeworkAssignmentManager $assignments,
        private readonly HomeworkDefinitionQuery $query,
        private readonly HomeworkTeacherScope $scope,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
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

        return $this->noStore($this->render('teacher/homework/show.html.twig', [
            'definition' => $definition,
            'assignment' => $assignment,
            'can_edit' => $canEdit,
            'can_assign' => $canAssign,
            'show_confirm' => $showConfirm && $canAssign,
            'eligible_count' => $eligibleCount,
            'errors' => $errors,
            'csrf_assign' => self::CSRF_ASSIGN,
        ]), $status);
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
