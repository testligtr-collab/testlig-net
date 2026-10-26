<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminCurriculumActionRequest;
use App\Dto\AdminCurriculumNodeRequest;
use App\Dto\AdminCurriculumProgramCreateRequest;
use App\Dto\AdminCurriculumSubjectRequest;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Subject;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Exception\CurriculumDeletionException;
use App\Exception\CurriculumException;
use App\Exception\CurriculumTopicException;
use App\Exception\CurriculumUnitException;
use App\Exception\LearningOutcomeException;
use App\Exception\SubjectException;
use App\Form\AdminCurriculumActionFormType;
use App\Form\AdminCurriculumNodeFormType;
use App\Form\AdminCurriculumProgramCreateFormType;
use App\Form\AdminCurriculumSubjectFormType;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\CurriculumTopicRepository;
use App\Repository\CurriculumUnitRepository;
use App\Repository\SubjectRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\CurriculumDraftDeletion;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\SubjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminCurriculumController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly CurriculumProgramRepository $programs,
        private readonly CurriculumUnitRepository $units,
        private readonly CurriculumTopicRepository $topics,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly SubjectRepository $subjects,
        private readonly SubjectManager $subjectManager,
        private readonly CurriculumProgramManager $programManager,
        private readonly CurriculumUnitManager $unitManager,
        private readonly CurriculumTopicManager $topicManager,
        private readonly CurriculumLearningOutcomeManager $outcomeManager,
        private readonly CurriculumDraftDeletion $deletion,
        private readonly FormFactoryInterface $forms,
        #[Autowire(service: 'limiter.admin_curriculum_mutate')]
        private readonly RateLimiterFactory $mutateLimiter,
        #[Autowire(service: 'limiter.admin_curriculum_publish')]
        private readonly RateLimiterFactory $publishLimiter,
        #[Autowire(service: 'limiter.admin_curriculum_archive')]
        private readonly RateLimiterFactory $archiveLimiter,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/programlar', name: 'app_admin_curriculum', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_VIEW)]
    public function list(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $grade = GradeLevel::tryFrom((int) $request->query->get('sinif', 0));
        $status = $this->statusFilter((string) $request->query->get('durum', ''));
        $subjectId = $this->uuidOrNull((string) $request->query->get('ders', ''));
        $page = max(1, (int) $request->query->get('sayfa', 1));
        $result = $this->programs->searchAdmin($q, $grade, $status, $subjectId, $page, 20);
        $pages = max(1, (int) ceil($result['total'] / 20));

        return $this->renderAdmin('admin/curriculum/list.html.twig', [
            'rows' => $result['rows'],
            'summary' => $this->programs->countByStatus(),
            'subjects' => $this->subjects->findActiveOrdered(),
            'grades' => GradeLevel::cases(),
            'filters' => [
                'q' => $q,
                'sinif' => $grade instanceof GradeLevel ? (string) $grade->value : '',
                'durum' => $status instanceof CurriculumStatus ? $status->value : '',
                'ders' => $subjectId instanceof Uuid ? $subjectId->toRfc4122() : '',
            ],
            'page' => min($page, $pages),
            'pages' => $pages,
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE),
            'can_catalog' => $this->isGranted(AdminPermission::ADMIN_CATALOG_VIEW),
        ]);
    }

    #[Route('/yonetim/programlar/yeni', name: 'app_admin_curriculum_new', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE)]
    public function create(Request $request): Response
    {
        $actor = $this->requireActorUser();
        $subjects = $this->subjects->findActiveOrdered();
        $subjectForm = $this->createForm(AdminCurriculumSubjectFormType::class, new AdminCurriculumSubjectRequest());
        $programForm = $this->createForm(AdminCurriculumProgramCreateFormType::class, new AdminCurriculumProgramCreateRequest(), [
            'subjects' => $subjects,
        ]);
        $subjectForm->handleRequest($request);
        $programForm->handleRequest($request);

        if ($subjectForm->isSubmitted()) {
            if ($this->formHasCsrfFailure($subjectForm)) {
                throw new AccessDeniedHttpException('CSRF doğrulaması başarısız.');
            }
            if ($subjectForm->isValid()) {
                /** @var AdminCurriculumSubjectRequest $data */
                $data = $subjectForm->getData();
                try {
                    $this->consume($this->mutateLimiter, 'curriculum-subject-'.$actor->getId()->toRfc4122());
                    $subject = $this->subjectManager->create($actor, $data->code, $data->name, $data->reasonCode);
                } catch (SubjectException $e) {
                    return $this->failForm($subjectForm, $e->getReason()->value, 'admin/curriculum/new.html.twig', $this->createContext($subjectForm, $programForm, $subjects));
                }

                return $this->redirectToRoute('app_admin_curriculum_new', ['ders' => $subject->getId()->toRfc4122()]);
            }

            return $this->renderCreate($subjectForm, $programForm, $subjects, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($programForm->isSubmitted()) {
            if ($this->formHasCsrfFailure($programForm)) {
                throw new AccessDeniedHttpException('CSRF doğrulaması başarısız.');
            }
            if ($programForm->isValid()) {
                /** @var AdminCurriculumProgramCreateRequest $data */
                $data = $programForm->getData();
                $subject = $this->freshSubject($data->subjectId);
                $grade = GradeLevel::tryFrom((int) $data->gradeLevel);
                if (!$subject instanceof Subject || !$grade instanceof GradeLevel) {
                    $programForm->addError(new FormError('Ders ve sınıf düzeyi yeniden çözülemedi.'));

                    return $this->renderCreate($subjectForm, $programForm, $subjects, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                try {
                    $this->consume($this->mutateLimiter, 'curriculum-program-'.$actor->getId()->toRfc4122());
                    $program = $this->programManager->createDraft($subject, $actor, $grade, $data->code, $data->name, $data->version, $data->reasonCode);
                } catch (CurriculumException $e) {
                    return $this->failForm($programForm, $e->getReason()->value, 'admin/curriculum/new.html.twig', $this->createContext($subjectForm, $programForm, $subjects));
                }

                return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
            }

            return $this->renderCreate($subjectForm, $programForm, $subjects, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderCreate($subjectForm, $programForm, $subjects);
    }

    #[Route('/yonetim/programlar/ders/{subject}/ad', name: 'app_admin_curriculum_subject_rename', methods: ['POST'], requirements: ['subject' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE)]
    public function renameSubject(Request $request, string $subject): Response
    {
        $found = $this->subjects->findOneById(Uuid::fromString($subject));
        if (!$found instanceof Subject) {
            throw new NotFoundHttpException('Kayıt bulunamadı.');
        }
        $payload = $this->renamePayload($request, 'admin_curriculum_rename_subject', 'admin_curriculum_rename_'.$found->getId()->toRfc4122());
        if (\is_string($payload)) {
            $subjects = $this->subjects->findActiveOrdered();
            $subjectForm = $this->createForm(AdminCurriculumSubjectFormType::class, new AdminCurriculumSubjectRequest());
            $programForm = $this->createForm(AdminCurriculumProgramCreateFormType::class, new AdminCurriculumProgramCreateRequest(), [
                'subjects' => $subjects,
            ]);

            return $this->renderCreate($subjectForm, $programForm, $subjects, Response::HTTP_UNPROCESSABLE_ENTITY, $payload);
        }
        try {
            $this->consume($this->mutateLimiter, 'curriculum-subject-rename-'.$this->requireActorId()->toRfc4122());
            $this->subjectManager->rename($found, $this->requireActorUser(), $payload['title'], $payload['reason']);
        } catch (SubjectException $e) {
            $message = $this->publicFailure($e->getReason()->value);
            if ('Bu işlem için yetkiniz yok.' === $message) {
                throw new AccessDeniedHttpException($message);
            }
            $subjects = $this->subjects->findActiveOrdered();
            $subjectForm = $this->createForm(AdminCurriculumSubjectFormType::class, new AdminCurriculumSubjectRequest());
            $programForm = $this->createForm(AdminCurriculumProgramCreateFormType::class, new AdminCurriculumProgramCreateRequest(), [
                'subjects' => $subjects,
            ]);

            return $this->renderCreate($subjectForm, $programForm, $subjects, Response::HTTP_UNPROCESSABLE_ENTITY, $message);
        }
        $this->addFlash('success', 'Ders adı güncellendi. Kod aynı kaldı.');

        return $this->redirectToRoute('app_admin_curriculum_new');
    }

    #[Route('/yonetim/programlar/{id}', name: 'app_admin_curriculum_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_VIEW)]
    public function show(string $id): Response
    {
        $program = $this->requireProgram($id);

        return $this->renderShow($program);
    }

    #[Route('/yonetim/programlar/{id}/ad', name: 'app_admin_curriculum_rename', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE)]
    public function renameProgram(Request $request, string $id): Response
    {
        $program = $this->requireProgram($id);
        $payload = $this->renamePayload($request, 'admin_curriculum_rename_program', 'admin_curriculum_rename_'.$program->getId()->toRfc4122());
        if (\is_string($payload)) {
            return $this->renderShow($program, Response::HTTP_UNPROCESSABLE_ENTITY, null, $payload);
        }
        try {
            $this->consume($this->mutateLimiter, 'curriculum-rename-'.$this->requireActorId()->toRfc4122());
            $this->programManager->renameDraft($program, $this->requireActorUser(), $payload['title'], $payload['reason']);
        } catch (CurriculumException $e) {
            return $this->failShow($program, $this->publicFailure($e->getReason()->value));
        }
        $this->addFlash('success', 'Program adı güncellendi. Kod, ders, sınıf düzeyi ve sürüm aynı kaldı.');

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    #[Route('/yonetim/programlar/{id}/{kind}/{node}/ad', name: 'app_admin_curriculum_rename_node', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'kind' => 'unite|konu|kazanim', 'node' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE)]
    public function renameNode(Request $request, string $id, string $kind, string $node): Response
    {
        $program = $this->requireProgram($id);
        $payload = $this->renamePayload($request, 'admin_curriculum_rename_'.$kind, 'admin_curriculum_rename_'.$node);
        if (\is_string($payload)) {
            return $this->renderShow($program, Response::HTTP_UNPROCESSABLE_ENTITY, null, $payload);
        }
        try {
            $this->consume($this->mutateLimiter, 'curriculum-rename-'.$this->requireActorId()->toRfc4122());
            $actor = $this->requireActorUser();
            if ('unite' === $kind) {
                $unit = $this->unitInProgram($node, $program) ?? throw new NotFoundHttpException('Kayıt bulunamadı.');
                $this->unitManager->rename($unit, $actor, $payload['title'], $payload['reason']);
            } elseif ('konu' === $kind) {
                $topic = $this->topicInProgram($node, $program) ?? throw new NotFoundHttpException('Kayıt bulunamadı.');
                $this->topicManager->rename($topic, $actor, $payload['title'], $payload['reason']);
            } else {
                $outcome = $this->outcomeInProgram($node, $program) ?? throw new NotFoundHttpException('Kayıt bulunamadı.');
                $this->outcomeManager->rename($outcome, $actor, $payload['title'], $payload['reason']);
            }
        } catch (CurriculumUnitException|CurriculumTopicException|LearningOutcomeException $e) {
            return $this->failShow($program, $this->publicFailure($e->getReason()->value));
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    #[Route('/yonetim/programlar/{id}/yayinla', name: 'app_admin_curriculum_publish', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_PUBLISH)]
    public function publish(Request $request, string $id): Response
    {
        $program = $this->requireProgram($id);
        $form = $this->actionForm('admin_curriculum_publish', [
            'Yayın' => AdminCurriculumActionRequest::REASON_PUBLISH,
        ], 'Programı yayınlamayı onaylıyorum', 'admin_curriculum_publish_'.$program->getId()->toRfc4122());
        $form->handleRequest($request);

        return $this->handleAction($form, $program, $this->publishLimiter, 'curriculum-publish', function (AdminCurriculumActionRequest $data) use ($program): void {
            $this->programManager->publish($program, $this->requireActorUser(), $data->reasonCode);
        });
    }

    #[Route('/yonetim/programlar/{id}/emekli', name: 'app_admin_curriculum_retire', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_ARCHIVE)]
    public function retire(Request $request, string $id): Response
    {
        $program = $this->requireProgram($id);
        $form = $this->actionForm('admin_curriculum_retire', [
            'Emekliye ayırma' => AdminCurriculumActionRequest::REASON_RETIRE,
        ], 'Programı emekliye ayırmayı onaylıyorum', 'admin_curriculum_retire_'.$program->getId()->toRfc4122());
        $form->handleRequest($request);

        return $this->handleAction($form, $program, $this->archiveLimiter, 'curriculum-retire', function (AdminCurriculumActionRequest $data) use ($program): void {
            $this->programManager->retire($program, $this->requireActorUser(), $data->reasonCode);
        });
    }

    #[Route('/yonetim/programlar/{id}/sil', name: 'app_admin_curriculum_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_ARCHIVE)]
    public function deleteProgram(Request $request, string $id): Response
    {
        $program = $this->requireProgram($id);

        return $this->deleteNode($request, $program, 'program', null, function () use ($program): void {
            $this->deletion->deleteProgram($program, $this->requireActorUser(), AdminCurriculumActionRequest::REASON_DELETE);
        });
    }

    #[Route('/yonetim/programlar/{id}/unite', name: 'app_admin_curriculum_unit', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE)]
    public function createUnit(Request $request, string $id): Response
    {
        $program = $this->requireProgram($id);
        $form = $this->nodeForm($request, 'curriculum_unit', 'admin_curriculum_unit_'.$program->getId()->toRfc4122(), [], 'Ad');
        if (!$form->isValid()) {
            return $this->invalidNode($form, $program);
        }
        /** @var AdminCurriculumNodeRequest $data */
        $data = $form->getData();
        $position = $this->position($form, $data->position);
        if (null === $position) {
            return $this->invalidNode($form, $program);
        }
        try {
            $this->consume($this->mutateLimiter, 'curriculum-unit-'.$this->requireActorId()->toRfc4122());
            $this->unitManager->create($program, $this->requireActorUser(), $data->code, $data->title, $position, $data->reasonCode);
        } catch (CurriculumUnitException $e) {
            return $this->failShow($program, $this->publicFailure($e->getReason()->value));
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    #[Route('/yonetim/programlar/{id}/konu', name: 'app_admin_curriculum_topic', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE)]
    public function createTopic(Request $request, string $id): Response
    {
        $program = $this->requireProgram($id);
        $parents = [];
        foreach ($this->units->findByProgram($program) as $unit) {
            $parents[$unit->getTitle()] = $unit->getId()->toRfc4122();
        }
        $form = $this->nodeForm($request, 'curriculum_topic', 'admin_curriculum_topic_'.$program->getId()->toRfc4122(), $parents, 'Ad', 'Ünite');
        if (!$form->isValid() || !$this->requireParent($form)) {
            return $this->invalidNode($form, $program);
        }
        /** @var AdminCurriculumNodeRequest $data */
        $data = $form->getData();
        $unit = $this->unitInProgram($data->parentId, $program);
        $position = $this->position($form, $data->position);
        if (!$unit instanceof CurriculumUnit || null === $position) {
            $form->get('parentId')->addError(new FormError('Üst kayıt bu programda yok.'));

            return $this->invalidNode($form, $program);
        }
        try {
            $this->consume($this->mutateLimiter, 'curriculum-topic-'.$this->requireActorId()->toRfc4122());
            $this->topicManager->createRoot($unit, $this->requireActorUser(), $data->code, $data->title, $position, $data->reasonCode);
        } catch (CurriculumTopicException $e) {
            return $this->failShow($program, $this->publicFailure($e->getReason()->value));
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    #[Route('/yonetim/programlar/{id}/kazanim', name: 'app_admin_curriculum_outcome', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE)]
    public function createOutcome(Request $request, string $id): Response
    {
        $program = $this->requireProgram($id);
        $parents = [];
        foreach ($this->topics->findByProgram($program) as $topic) {
            $parents[$topic->getTitle()] = $topic->getId()->toRfc4122();
        }
        $form = $this->nodeForm($request, 'curriculum_outcome', 'admin_curriculum_outcome_'.$program->getId()->toRfc4122(), $parents, 'Kazanım', 'Konu');
        if (!$form->isValid() || !$this->requireParent($form)) {
            return $this->invalidNode($form, $program);
        }
        /** @var AdminCurriculumNodeRequest $data */
        $data = $form->getData();
        $topic = $this->topicInProgram($data->parentId, $program);
        $position = $this->position($form, $data->position);
        if (!$topic instanceof CurriculumTopic || null === $position) {
            $form->get('parentId')->addError(new FormError('Üst kayıt bu programda yok.'));

            return $this->invalidNode($form, $program);
        }
        try {
            $this->consume($this->mutateLimiter, 'curriculum-outcome-'.$this->requireActorId()->toRfc4122());
            $this->outcomeManager->create($topic, $this->requireActorUser(), $data->code, $data->title, $position, $data->reasonCode);
        } catch (LearningOutcomeException $e) {
            return $this->failShow($program, $this->publicFailure($e->getReason()->value));
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    #[Route('/yonetim/programlar/{id}/{kind}/{node}/arsivle', name: 'app_admin_curriculum_archive_node', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'kind' => 'unite|konu|kazanim', 'node' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_ARCHIVE)]
    public function archiveNode(Request $request, string $id, string $kind, string $node): Response
    {
        $program = $this->requireProgram($id);
        $form = $this->actionForm('admin_curriculum_archive_'.$kind, [
            'Arşiv' => AdminCurriculumActionRequest::REASON_ARCHIVE,
        ], 'Bu kaydı arşivlemeyi onaylıyorum', 'admin_curriculum_archive_'.$node);
        $form->handleRequest($request);

        return $this->handleAction($form, $program, $this->archiveLimiter, 'curriculum-archive', function (AdminCurriculumActionRequest $data) use ($program, $kind, $node): void {
            $actor = $this->requireActorUser();
            if ('unite' === $kind) {
                $unit = $this->unitInProgram($node, $program) ?? throw new NotFoundHttpException('Kayıt bulunamadı.');
                $this->unitManager->archive($unit, $actor, $data->reasonCode);
            } elseif ('konu' === $kind) {
                $topic = $this->topicInProgram($node, $program) ?? throw new NotFoundHttpException('Kayıt bulunamadı.');
                $this->topicManager->archive($topic, $actor, $data->reasonCode);
            } else {
                $outcome = $this->outcomeInProgram($node, $program) ?? throw new NotFoundHttpException('Kayıt bulunamadı.');
                $this->outcomeManager->archive($outcome, $actor, $data->reasonCode);
            }
        });
    }

    #[Route('/yonetim/programlar/{id}/{kind}/{node}/sil', name: 'app_admin_curriculum_delete_node', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'kind' => 'unite|konu|kazanim', 'node' => Requirement::UUID])]
    #[IsGranted(AdminPermission::ADMIN_CURRICULUM_ARCHIVE)]
    public function deleteChild(Request $request, string $id, string $kind, string $node): Response
    {
        $program = $this->requireProgram($id);

        return $this->deleteNode($request, $program, $kind, $node, function () use ($program, $kind, $node): void {
            $actor = $this->requireActorUser();
            if ('unite' === $kind) {
                $unit = $this->unitInProgram($node, $program) ?? throw new CurriculumDeletionException('Kayıt bu programda yok.');
                $this->deletion->deleteUnit($unit, $actor, AdminCurriculumActionRequest::REASON_DELETE);
            } elseif ('konu' === $kind) {
                $topic = $this->topicInProgram($node, $program) ?? throw new CurriculumDeletionException('Kayıt bu programda yok.');
                $this->deletion->deleteTopic($topic, $actor, AdminCurriculumActionRequest::REASON_DELETE);
            } else {
                $outcome = $this->outcomeInProgram($node, $program) ?? throw new CurriculumDeletionException('Kayıt bu programda yok.');
                $this->deletion->deleteOutcome($outcome, $actor, AdminCurriculumActionRequest::REASON_DELETE);
            }
        });
    }

    private function deleteNode(Request $request, CurriculumProgram $program, string $kind, ?string $node, \Closure $delete): Response
    {
        $tokenId = 'admin_curriculum_delete_'.($node ?? $program->getId()->toRfc4122());
        $form = $this->actionForm('admin_curriculum_delete_'.$kind, [
            'Kullanılmamış taslağı silme' => AdminCurriculumActionRequest::REASON_DELETE,
        ], 'Bağımlılığı olmadığını ve kaydın kalıcı silineceğini onaylıyorum', $tokenId);
        $form->handleRequest($request);
        if ($this->formHasCsrfFailure($form)) {
            throw new AccessDeniedHttpException('CSRF doğrulaması başarısız.');
        }
        if (!$form->isValid()) {
            return $this->renderShow($program, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
        }
        try {
            $this->consume($this->archiveLimiter, 'curriculum-delete-'.$this->requireActorId()->toRfc4122());
            $delete();
        } catch (CurriculumDeletionException $e) {
            if ('Bu işlem için yetkiniz yok.' === $e->getMessage()) {
                throw new AccessDeniedHttpException('Bu işlem için yetkiniz yok.');
            }

            return $this->failShow($program, $e->getMessage());
        }

        $this->addFlash('success', 'Kullanılmamış taslak silindi. Yeni kayıt yeni kimlik alır.');

        return 'program' === $kind
            ? $this->redirectToRoute('app_admin_curriculum')
            : $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    /**
     * @param FormInterface<mixed>                         $form
     * @param \Closure(AdminCurriculumActionRequest): void $action
     */
    private function handleAction(FormInterface $form, CurriculumProgram $program, RateLimiterFactory $limiter, string $bucket, \Closure $action): Response
    {
        if ($this->formHasCsrfFailure($form)) {
            throw new AccessDeniedHttpException('CSRF doğrulaması başarısız.');
        }
        if (!$form->isValid()) {
            return $this->renderShow($program, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
        }
        /** @var AdminCurriculumActionRequest $data */
        $data = $form->getData();
        try {
            $this->consume($limiter, $bucket.'-'.$this->requireActorId()->toRfc4122());
            $action($data);
        } catch (CurriculumException|CurriculumUnitException|CurriculumTopicException|LearningOutcomeException $e) {
            return $this->failShow($program, $this->publicFailure($e->getReason()->value));
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    /**
     * @param array<string, string> $parents
     *
     * @return FormInterface<mixed>
     */
    private function nodeForm(Request $request, string $name, string $csrf, array $parents, string $titleLabel, string $parentLabel = 'Üst kayıt'): FormInterface
    {
        $form = $this->forms->createNamed($name, AdminCurriculumNodeFormType::class, new AdminCurriculumNodeRequest(), [
            'csrf_token_id' => $csrf,
            'parents' => $parents,
            'parent_label' => $parentLabel,
            'title_label' => $titleLabel,
        ]);
        $form->handleRequest($request);
        if ($this->formHasCsrfFailure($form)) {
            throw new AccessDeniedHttpException('CSRF doğrulaması başarısız.');
        }

        return $form;
    }

    /**
     * @param array<string, string> $reasons
     *
     * @return FormInterface<mixed>
     */
    private function actionForm(string $name, array $reasons, string $confirm, string $csrf): FormInterface
    {
        return $this->forms->createNamed($name, AdminCurriculumActionFormType::class, new AdminCurriculumActionRequest(), [
            'reasons' => $reasons,
            'confirm_label' => $confirm,
            'csrf_token_id' => $csrf,
        ]);
    }

    /**
     * @param FormInterface<mixed>|null $invalid
     */
    private function renderShow(CurriculumProgram $program, int $status = Response::HTTP_OK, ?FormInterface $invalid = null, ?string $renameError = null): Response
    {
        $units = $this->units->findByProgram($program);
        $topics = $this->topics->findByProgram($program);
        $outcomes = $this->outcomes->findByProgram($program);
        $topicsByUnit = [];
        $childTopics = [];
        foreach ($topics as $topic) {
            $topicsByUnit[$topic->getUnit()->getId()->toRfc4122()][] = $topic;
            if (null !== $topic->getParent()) {
                $childTopics[$topic->getParent()->getId()->toRfc4122()] = true;
            }
        }
        $outcomesByTopic = [];
        foreach ($outcomes as $outcome) {
            $outcomesByTopic[$outcome->getTopic()->getId()->toRfc4122()][] = $outcome;
        }
        $draft = CurriculumStatus::Draft === $program->getStatus();
        $response = $this->renderAdmin('admin/curriculum/show.html.twig', [
            'program' => $program,
            'units' => $units,
            'topics_by_unit' => $topicsByUnit,
            'outcomes_by_topic' => $outcomesByTopic,
            'child_topics' => $childTopics,
            'linked_outcomes' => $this->linkedOutcomeIds($program),
            'course_count' => $this->dependencyCount('classroom_courses', 'curriculum_program_id', $program->getId()),
            'alignment_count' => $this->dependencyCount('question_revision_alignments', 'curriculum_program_id', $program->getId())
                + $this->dependencyCount('learning_content_outcome_alignments', 'curriculum_program_id', $program->getId()),
            'access_note' => $this->accessNote($program),
            'can_manage' => $draft && $this->isGranted(AdminPermission::ADMIN_CURRICULUM_MANAGE),
            'can_publish' => $draft && $this->isGranted(AdminPermission::ADMIN_CURRICULUM_PUBLISH),
            'can_archive' => $this->isGranted(AdminPermission::ADMIN_CURRICULUM_ARCHIVE),
            'can_catalog' => $this->isGranted(AdminPermission::ADMIN_CATALOG_VIEW),
            'invalid_form' => $invalid?->createView(),
            'unit_form' => $this->prefer($invalid, $this->namedForm('curriculum_unit', 'admin_curriculum_unit_'.$program->getId()->toRfc4122(), [], 'Ad'))->createView(),
            'topic_form' => $this->prefer($invalid, $this->namedForm('curriculum_topic', 'admin_curriculum_topic_'.$program->getId()->toRfc4122(), $this->choices($units), 'Ad', 'Ünite'))->createView(),
            'outcome_form' => $this->prefer($invalid, $this->namedForm('curriculum_outcome', 'admin_curriculum_outcome_'.$program->getId()->toRfc4122(), $this->topicChoices($topics), 'Kazanım', 'Konu'))->createView(),
            'publish_form' => $this->prefer($invalid, $this->actionForm('admin_curriculum_publish', ['Yayın' => AdminCurriculumActionRequest::REASON_PUBLISH], 'Programı yayınlamayı onaylıyorum', 'admin_curriculum_publish_'.$program->getId()->toRfc4122()))->createView(),
            'retire_form' => $this->prefer($invalid, $this->actionForm('admin_curriculum_retire', ['Emekliye ayırma' => AdminCurriculumActionRequest::REASON_RETIRE], 'Programı emekliye ayırmayı onaylıyorum', 'admin_curriculum_retire_'.$program->getId()->toRfc4122()))->createView(),
            'delete_form' => $this->prefer($invalid, $this->actionForm('admin_curriculum_delete_program', ['Kullanılmamış taslağı silme' => AdminCurriculumActionRequest::REASON_DELETE], 'Bağımlılığı olmadığını ve kaydın kalıcı silineceğini onaylıyorum', 'admin_curriculum_delete_'.$program->getId()->toRfc4122()))->createView(),
            'rename_error' => $renameError,
        ], new Response(status: $status));

        return $response;
    }

    /**
     * @param list<CurriculumUnit> $units
     *
     * @return array<string, string>
     */
    private function choices(array $units): array
    {
        $choices = [];
        foreach ($units as $unit) {
            $choices[$unit->getTitle()] = $unit->getId()->toRfc4122();
        }

        return $choices;
    }

    /**
     * @param list<CurriculumTopic> $topics
     *
     * @return array<string, string>
     */
    private function topicChoices(array $topics): array
    {
        $choices = [];
        foreach ($topics as $topic) {
            $choices[$topic->getTitle()] = $topic->getId()->toRfc4122();
        }

        return $choices;
    }

    /**
     * @param array<string, string> $parents
     *
     * @return FormInterface<mixed>
     */
    private function namedForm(string $name, string $csrf, array $parents, string $title, string $parentLabel = 'Üst kayıt'): FormInterface
    {
        return $this->forms->createNamed($name, AdminCurriculumNodeFormType::class, new AdminCurriculumNodeRequest(), [
            'csrf_token_id' => $csrf,
            'parents' => $parents,
            'parent_label' => $parentLabel,
            'title_label' => $title,
        ]);
    }

    /**
     * @param FormInterface<mixed>|null $invalid
     * @param FormInterface<mixed>      $fresh
     *
     * @return FormInterface<mixed>
     */
    private function prefer(?FormInterface $invalid, FormInterface $fresh): FormInterface
    {
        return $invalid instanceof FormInterface && $invalid->getName() === $fresh->getName() ? $invalid : $fresh;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function invalidNode(FormInterface $form, CurriculumProgram $program): Response
    {
        return $this->renderShow($program, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
    }

    private function failShow(CurriculumProgram $program, string $message): Response
    {
        if ('Bu işlem için yetkiniz yok.' === $message) {
            throw new AccessDeniedHttpException($message);
        }
        if ('Kayıt bulunamadı.' === $message) {
            throw new NotFoundHttpException($message);
        }
        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $program->getId()->toRfc4122()]);
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $context
     */
    private function failForm(FormInterface $form, string $reason, string $view, array $context): Response
    {
        $message = $this->publicFailure($reason);
        if ('Bu işlem için yetkiniz yok.' === $message) {
            throw new AccessDeniedHttpException($message);
        }
        $form->addError(new FormError($message));

        return $this->renderAdmin($view, $context, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param FormInterface<mixed> $subjectForm
     * @param FormInterface<mixed> $programForm
     * @param list<Subject>        $subjects
     *
     * @return array<string, mixed>
     */
    private function createContext(FormInterface $subjectForm, FormInterface $programForm, array $subjects): array
    {
        return [
            'subject_form' => $subjectForm->createView(),
            'program_form' => $programForm->createView(),
            'subjects' => $subjects,
            'can_catalog' => $this->isGranted(AdminPermission::ADMIN_CATALOG_VIEW),
        ];
    }

    /**
     * @param FormInterface<mixed> $subjectForm
     * @param FormInterface<mixed> $programForm
     * @param list<Subject>        $subjects
     */
    private function renderCreate(FormInterface $subjectForm, FormInterface $programForm, array $subjects, int $status = Response::HTTP_OK, ?string $renameError = null): Response
    {
        $context = $this->createContext($subjectForm, $programForm, $subjects);
        $context['rename_error'] = $renameError;

        return $this->renderAdmin('admin/curriculum/new.html.twig', $context, new Response(status: $status));
    }

    /**
     * @return array{title: string, reason: string}|string
     */
    private function renamePayload(Request $request, string $name, string $tokenId): array|string
    {
        $payload = $request->request->has($name) ? $request->request->all($name) : [];
        $token = (string) ($payload['_token'] ?? '');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw new AccessDeniedHttpException('CSRF doğrulaması başarısız.');
        }
        $title = trim((string) ($payload['title'] ?? ''));
        $reason = trim((string) ($payload['reasonCode'] ?? ''));
        $confirm = (string) ($payload['confirm'] ?? '');
        if ('' === $title) {
            return 'Ad gerekli.';
        }
        if ('' === $reason) {
            return 'İşlem gerekçesi gerekli.';
        }
        if (!\in_array($reason, [AdminCurriculumProgramCreateRequest::REASON_SETUP, AdminCurriculumProgramCreateRequest::REASON_CORRECTION], true)) {
            return 'İşlem gerekçesi geçersiz.';
        }
        if ('1' !== $confirm) {
            return 'Onay kutusu gerekli.';
        }

        return ['title' => $title, 'reason' => $reason];
    }

    private function requireProgram(string $id): CurriculumProgram
    {
        $uuid = $this->uuidOrNull($id);
        $program = $uuid instanceof Uuid ? $this->programs->findOneById($uuid) : null;
        if (!$program instanceof CurriculumProgram) {
            throw new NotFoundHttpException('Kayıt bulunamadı.');
        }

        return $program;
    }

    private function freshSubject(string $id): ?Subject
    {
        $uuid = $this->uuidOrNull($id);

        return $uuid instanceof Uuid ? $this->subjects->find($uuid) : null;
    }

    private function unitInProgram(string $id, CurriculumProgram $program): ?CurriculumUnit
    {
        $uuid = $this->uuidOrNull($id);
        $unit = $uuid instanceof Uuid ? $this->units->findOneById($uuid) : null;

        return $unit instanceof CurriculumUnit && $unit->getProgram()->getId()->equals($program->getId()) ? $unit : null;
    }

    private function topicInProgram(string $id, CurriculumProgram $program): ?CurriculumTopic
    {
        $uuid = $this->uuidOrNull($id);
        $topic = $uuid instanceof Uuid ? $this->topics->findOneById($uuid) : null;

        return $topic instanceof CurriculumTopic && $topic->getUnit()->getProgram()->getId()->equals($program->getId()) ? $topic : null;
    }

    private function outcomeInProgram(string $id, CurriculumProgram $program): ?CurriculumLearningOutcome
    {
        $uuid = $this->uuidOrNull($id);
        $outcome = $uuid instanceof Uuid ? $this->outcomes->findOneById($uuid) : null;

        return $outcome instanceof CurriculumLearningOutcome && $outcome->getCurriculumProgram()->getId()->equals($program->getId()) ? $outcome : null;
    }

    private function requireParent(FormInterface $form): bool
    {
        if (!$form->has('parentId')) {
            return true;
        }
        $data = $form->getData();
        if ($data instanceof AdminCurriculumNodeRequest && '' !== $data->parentId) {
            return true;
        }
        $form->get('parentId')->addError(new FormError('Üst kayıt gerekli.'));

        return false;
    }

    private function position(FormInterface $form, string $raw): ?int
    {
        if (1 !== preg_match('/^[1-9][0-9]{0,5}$/', $raw)) {
            $form->get('position')->addError(new FormError('Sıra 1 veya daha büyük bir tam sayı olmalıdır.'));

            return null;
        }

        return (int) $raw;
    }

    private function uuidOrNull(string $value): ?Uuid
    {
        return Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }

    private function statusFilter(string $value): ?CurriculumStatus
    {
        return match ($value) {
            'draft', 'published', 'retired' => CurriculumStatus::from($value),
            default => null,
        };
    }

    private function accessNote(CurriculumProgram $program): string
    {
        return match ($program->getStatus()) {
            CurriculumStatus::Draft => 'Program taslak. Alt kayıtlar kendi durumunu korur; öğrenci kataloğu bu program yayında olmadığı için açılmaz.',
            CurriculumStatus::Retired => 'Program emekli. Alt kayıtların kendi durumları değiştirilmedi. Geçmiş içerik ve deneme bağlantıları durur.',
            CurriculumStatus::Published => 'Program yayında. Öğrenci kataloğundaki ders, ünite ve konu yine kendi yayın durumuna ve üst zincire bağlıdır.',
        };
    }

    /**
     * @return array<string, true>
     */
    private function linkedOutcomeIds(CurriculumProgram $program): array
    {
        $connection = $this->programs->getEntityManager()->getConnection();
        $binary = $program->getId()->toBinary();
        $ids = [];
        foreach ([
            'SELECT learning_outcome_id FROM question_revision_alignments WHERE curriculum_program_id = :id',
            'SELECT learning_outcome_id FROM learning_content_outcome_alignments WHERE curriculum_program_id = :id',
        ] as $sql) {
            $rows = $connection->fetchFirstColumn($sql, ['id' => $binary], ['id' => \Doctrine\DBAL\ParameterType::BINARY]);
            foreach ($rows as $row) {
                if (\is_string($row) && 16 === \strlen($row)) {
                    $ids[Uuid::fromBinary($row)->toRfc4122()] = true;
                }
            }
        }

        return $ids;
    }

    private function dependencyCount(string $table, string $column, Uuid $id): int
    {
        $allowed = [
            'classroom_courses.curriculum_program_id',
            'question_revision_alignments.curriculum_program_id',
            'learning_content_outcome_alignments.curriculum_program_id',
        ];
        if (!\in_array($table.'.'.$column, $allowed, true)) {
            return 0;
        }

        return (int) $this->programs->getEntityManager()->getConnection()->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE %s = :id', $table, $column),
            ['id' => $id->toBinary()],
            ['id' => \Doctrine\DBAL\ParameterType::BINARY],
        );
    }

    private function publicFailure(string $reason): string
    {
        return match ($reason) {
            'unauthorized' => 'Bu işlem için yetkiniz yok.',
            'conflict' => 'Bu kod veya sıra bu kapsamda kullanılıyor.',
            'invalid_transition' => 'Bu durum geçişi yapılamaz.',
            'program_not_draft' => 'Yayımlanmış programın yapısı değişmez. Yeni bir program sürümü açın.',
            'subject_archived' => 'Arşivlenmiş derse kayıt eklenmez.',
            'date_overlap' => 'Aynı ders ve sınıf düzeyinde yayımlı başka program var.',
            'not_found' => 'Kayıt bulunamadı.',
            'active_children' => 'Aktif alt kayıt varken arşivlenemez.',
            default => 'Girilen değer geçerli değil.',
        };
    }

    private function consume(RateLimiterFactory $factory, string $key): void
    {
        $rateLimit = $factory->create($key)->consume(1);
        if (!$rateLimit->isAccepted()) {
            $seconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }
    }
}
