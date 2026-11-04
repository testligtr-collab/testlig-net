<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\HomeworkSubmissionException;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Homework\HomeworkStudentQuery;
use App\Service\Homework\HomeworkSubmissionManager;
use App\Service\Homework\HomeworkText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Student homework list + text draft/submit. No evaluation/file/parent views.
 */
#[Route('/ogrenci/odevler')]
#[IsGranted('ROLE_STUDENT')]
final class StudentHomeworkController extends AbstractController
{
    private const CSRF_DRAFT = 'student_homework_draft';
    private const CSRF_SUBMIT = 'student_homework_submit';
    private const ID = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function __construct(
        private readonly HomeworkStudentQuery $query,
        private readonly HomeworkSubmissionManager $manager,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
    ) {
    }

    #[Route('', name: 'app_student_homeworks', methods: ['GET'])]
    public function index(): Response
    {
        $actor = $this->account();
        $rows = $this->query->listFor($actor);

        return $this->noStore($this->render('student/homework/index.html.twig', [
            'rows' => $rows,
        ]));
    }

    #[Route('/{id}', name: 'app_student_homework_show', methods: ['GET'], requirements: ['id' => self::ID])]
    public function show(string $id): Response
    {
        $actor = $this->account();
        $detail = $this->query->detailFor($actor, $this->uuid($id));
        if (null === $detail) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->noStore($this->render('student/homework/show.html.twig', [
            'detail' => $detail,
            'errors' => [],
            'values' => [
                'body' => $detail->body,
                'revision' => $detail->revisionNumber,
                'confirm' => false,
            ],
            'body_max' => HomeworkText::BODY_MAX,
        ]));
    }

    #[Route('/{id}/taslak', name: 'app_student_homework_draft', methods: ['POST'], requirements: ['id' => self::ID])]
    public function draft(string $id, Request $request): Response
    {
        $actor = $this->account();
        $this->assertCsrf($request, self::CSRF_DRAFT);
        $assignmentId = $this->uuid($id);
        $body = $request->request->getString('body');
        $revision = $request->request->getInt('revision');
        $values = ['body' => $body, 'revision' => $revision, 'confirm' => false];

        try {
            $this->manager->saveDraft($actor, $assignmentId, $body, $revision);
            $this->addFlash('success', 'Taslak kaydedildi. Ödevin henüz gönderilmedi.');

            return $this->redirectToRoute('app_student_homework_show', ['id' => $id]);
        } catch (HomeworkSubmissionException $e) {
            return $this->failure($actor, $assignmentId, $e, $values, self::CSRF_DRAFT);
        }
    }

    #[Route('/{id}/gonder', name: 'app_student_homework_submit', methods: ['POST'], requirements: ['id' => self::ID])]
    public function submit(string $id, Request $request): Response
    {
        $actor = $this->account();
        $this->assertCsrf($request, self::CSRF_SUBMIT);
        $assignmentId = $this->uuid($id);
        $body = $request->request->getString('body');
        $revision = $request->request->getInt('revision');
        $confirmed = $request->request->getBoolean('confirm_submit');
        $values = ['body' => $body, 'revision' => $revision, 'confirm' => $confirmed];

        try {
            $this->manager->submit($actor, $assignmentId, $body, $revision, $confirmed);
            $this->addFlash('success', 'Ödevin gönderildi.');

            return $this->redirectToRoute('app_student_homework_show', ['id' => $id]);
        } catch (HomeworkSubmissionException $e) {
            return $this->failure($actor, $assignmentId, $e, $values, self::CSRF_SUBMIT);
        }
    }

    /**
     * @param array{body: string, revision: int, confirm: bool} $values
     */
    private function failure(
        User $actor,
        Uuid $assignmentId,
        HomeworkSubmissionException $e,
        array $values,
        string $csrfIntent,
    ): Response {
        if (HomeworkSubmissionException::REASON_NOT_FOUND === $e->getReason()) {
            throw new NotFoundHttpException('Not Found');
        }
        if (HomeworkSubmissionException::REASON_FORBIDDEN === $e->getReason()) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $detail = $this->query->detailFor($actor, $assignmentId);
        if (null === $detail) {
            throw new NotFoundHttpException('Not Found');
        }

        // Stale revision: do not apply client body onto the stored draft in the form state from DB.
        if (HomeworkSubmissionException::REASON_STALE_REVISION === $e->getReason()) {
            $values['body'] = $detail->body;
            $values['revision'] = $detail->revisionNumber;
        }

        $status = match ($e->getReason()) {
            HomeworkSubmissionException::REASON_INVALID_INPUT,
            HomeworkSubmissionException::REASON_CONFIRMATION_REQUIRED,
            HomeworkSubmissionException::REASON_DUE_PASSED,
            HomeworkSubmissionException::REASON_ALREADY_SUBMITTED,
            HomeworkSubmissionException::REASON_CLOSED,
            HomeworkSubmissionException::REASON_STALE_REVISION,
            HomeworkSubmissionException::REASON_CONFLICT => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_REQUEST,
        };

        $response = $this->render('student/homework/show.html.twig', [
            'detail' => $detail,
            'errors' => $e->getFieldErrors() ?: ['_form' => $e->getMessage()],
            'form_error' => $e->getMessage(),
            'values' => $values,
            'body_max' => HomeworkText::BODY_MAX,
            'csrf_intent' => $csrfIntent,
        ]);
        $response->setStatusCode($status);

        return $this->noStore($response);
    }

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->activeUsers->isActiveAndVerified($user)) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        return $user;
    }

    private function uuid(string $id): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Not Found');
        }
    }

    private function assertCsrf(Request $request, string $intent): void
    {
        if (!$this->isCsrfTokenValid($intent, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Forbidden');
        }
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
