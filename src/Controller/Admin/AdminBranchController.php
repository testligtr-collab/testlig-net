<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminBranchCreateRequest;
use App\Dto\AdminBranchStatusRequest;
use App\Dto\AdminBranchUpdateRequest;
use App\Entity\InstitutionBranch;
use App\Enum\CommerceFailureReason;
use App\Enum\InstitutionFailureReason;
use App\Exception\CommerceException;
use App\Exception\InstitutionOperationException;
use App\Form\AdminBranchCreateFormType;
use App\Form\AdminBranchStatusFormType;
use App\Form\AdminBranchUpdateFormType;
use App\Repository\InstitutionBranchRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminBranchQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\InstitutionBranchCreator;
use App\Service\InstitutionBranchEditor;
use App\Service\InstitutionBranchStatusManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminBranchController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminBranchQuery $branchQuery,
        private readonly InstitutionBranchRepository $branches,
        private readonly InstitutionBranchCreator $branchCreator,
        private readonly InstitutionBranchEditor $branchEditor,
        private readonly InstitutionBranchStatusManager $branchStatus,
        #[Autowire(service: 'limiter.admin_branch_create')]
        private readonly RateLimiterFactory $createLimiter,
        #[Autowire(service: 'limiter.admin_branch_update')]
        private readonly RateLimiterFactory $updateLimiter,
        #[Autowire(service: 'limiter.admin_branch_status')]
        private readonly RateLimiterFactory $statusLimiter,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/subeler', name: 'app_admin_branches', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_BRANCHES_VIEW)]
    public function list(Request $request): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'institution' => $request->query->getString('institution'),
            'kind' => $request->query->getString('kind'),
            'status' => $request->query->getString('status'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];
        $actorId = $this->requireActorId();

        try {
            $result = $this->branchQuery->listBranches($actorId, $filters);
            $summary = $this->branchQuery->summarize($actorId);
            $institutions = $this->branchQuery->institutionChoices($actorId);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/branches/list.html.twig', [
            'result' => $result,
            'summary' => $summary,
            'filters' => $filters,
            'institutions' => $institutions,
            'can_create' => $this->isGranted(AdminPermission::ADMIN_BRANCHES_CREATE),
        ]);
    }

    #[Route('/yonetim/subeler/yeni', name: 'app_admin_branch_new', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_BRANCHES_CREATE)]
    public function newForm(#[MapQueryParameter] string $kurum = ''): Response
    {
        $dto = new AdminBranchCreateRequest();
        if (Uuid::isValid($kurum)) {
            $dto->institutionId = $kurum;
        }
        $form = $this->createForm(AdminBranchCreateFormType::class, $dto, [
            'institutions' => $this->institutionChoices(),
            'action' => $this->generateUrl('app_admin_branch_create'),
            'method' => 'POST',
        ]);

        return $this->renderNew($form);
    }

    #[Route('/yonetim/subeler', name: 'app_admin_branch_create', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_BRANCHES_CREATE)]
    public function create(Request $request): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_branch_create'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $dto = new AdminBranchCreateRequest();
        $form = $this->createForm(AdminBranchCreateFormType::class, $dto, [
            'institutions' => $this->institutionChoices(),
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->consume($this->createLimiter, $actorId->toRfc4122().':create');

        try {
            $branch = $this->branchCreator->create(
                $this->requireActorUser(),
                Uuid::fromString($dto->institutionId),
                $dto->name,
                $dto->code,
                $dto->kind ?? throw InstitutionOperationException::invalidInput(),
                $dto->reasonCode,
            );
        } catch (InstitutionOperationException $e) {
            return $this->renderCreateFailure($form, $e);
        }

        $this->addFlash('success', 'Şube kaydı oluşturuldu.');
        $response = $this->redirectToRoute('app_admin_branch_detail', ['id' => $branch->getId()]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/subeler/{id}', name: 'app_admin_branch_detail', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_BRANCHES_VIEW)]
    public function detail(Uuid $id): Response
    {
        try {
            $branch = $this->branchQuery->getDetail($this->requireActorId(), $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderDetail($branch, $this->updateForm($branch), $this->statusForm($branch));
    }

    #[Route('/yonetim/subeler/{id}', name: 'app_admin_branch_update', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_BRANCHES_MANAGE)]
    public function update(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_branch_update'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);
        $branch = $this->requireDetail($id);
        $entity = $this->branches->findOneById($id);
        if (!$entity instanceof InstitutionBranch) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }
        $institutionId = $entity->getInstitution()->getId();

        $dto = new AdminBranchUpdateRequest();
        $form = $this->createForm(AdminBranchUpdateFormType::class, $dto, ['branch_id' => $id]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderDetail($branch, $form->createView(), $this->statusForm($branch), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->consume($this->updateLimiter, $actorId->toRfc4122().':'.$id->toRfc4122().':update');

        try {
            $this->branchEditor->update(
                $id,
                $this->requireActorUser(),
                $dto->name,
                $dto->code,
                $dto->kind ?? throw InstitutionOperationException::invalidInput(),
                $dto->reasonCode,
            );
        } catch (InstitutionOperationException $e) {
            return $this->renderUpdateFailure($branch, $form, $e);
        }

        $fresh = $this->branches->findOneById($id);
        if (!$fresh instanceof InstitutionBranch || !$fresh->getInstitution()->getId()->equals($institutionId)) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        $this->addFlash('success', 'Şube kaydı güncellendi.');
        $response = $this->redirectToRoute('app_admin_branch_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/subeler/{id}/durum', name: 'app_admin_branch_status', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_BRANCHES_MANAGE)]
    public function updateStatus(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_branch_status'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);
        $branch = $this->requireDetail($id);

        $dto = new AdminBranchStatusRequest();
        $form = $this->createForm(AdminBranchStatusFormType::class, $dto, [
            'branch_id' => $id,
            'allowed_targets' => $branch->allowedStatusTargets,
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderDetail($branch, $this->updateForm($branch), $form->createView(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->consume($this->statusLimiter, $actorId->toRfc4122().':'.$id->toRfc4122().':status');

        try {
            $actor = $this->requireActorUser();
            match ($dto->action) {
                AdminBranchStatusRequest::ACTION_ACTIVATE => $this->branchStatus->activate($id, $actor, $dto->reasonCode),
                AdminBranchStatusRequest::ACTION_SUSPEND => $this->branchStatus->suspend($id, $actor, $dto->reasonCode),
                AdminBranchStatusRequest::ACTION_ARCHIVE => $this->branchStatus->archive($id, $actor, $dto->reasonCode),
                default => throw InstitutionOperationException::invalidInput(),
            };
        } catch (InstitutionOperationException $e) {
            $this->flashBranchException($e);

            return $this->redirectToRoute('app_admin_branch_detail', ['id' => $id]);
        }

        $this->addFlash('success', 'Şube durumu güncellendi.');
        $response = $this->redirectToRoute('app_admin_branch_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    /**
     * @return list<\App\Dto\AdminBranchInstitutionChoice>
     */
    private function institutionChoices(): array
    {
        try {
            return $this->branchQuery->institutionChoices($this->requireActorId());
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    private function requireDetail(Uuid $id): \App\Dto\AdminBranchDetailView
    {
        try {
            return $this->branchQuery->getDetail($this->requireActorId(), $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    private function updateForm(\App\Dto\AdminBranchDetailView $branch): \Symfony\Component\Form\FormView
    {
        $dto = new AdminBranchUpdateRequest();
        $dto->name = $branch->name;
        $dto->code = $branch->code;
        $dto->kind = $branch->kind;

        return $this->createForm(AdminBranchUpdateFormType::class, $dto, [
            'branch_id' => Uuid::fromString($branch->id),
            'action' => $this->generateUrl('app_admin_branch_update', ['id' => $branch->id]),
            'method' => 'POST',
        ])->createView();
    }

    private function statusForm(\App\Dto\AdminBranchDetailView $branch): ?\Symfony\Component\Form\FormView
    {
        if (!$branch->canManage || [] === $branch->allowedStatusTargets) {
            return null;
        }

        return $this->createForm(AdminBranchStatusFormType::class, new AdminBranchStatusRequest(), [
            'branch_id' => Uuid::fromString($branch->id),
            'allowed_targets' => $branch->allowedStatusTargets,
            'action' => $this->generateUrl('app_admin_branch_status', ['id' => $branch->id]),
            'method' => 'POST',
        ])->createView();
    }

    /**
     * @param FormInterface<AdminBranchCreateRequest> $form
     */
    private function renderNew(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->renderAdmin('admin/branches/new.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $status));
    }

    private function renderDetail(
        \App\Dto\AdminBranchDetailView $branch,
        \Symfony\Component\Form\FormView $updateForm,
        ?\Symfony\Component\Form\FormView $statusForm,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->renderAdmin('admin/branches/detail.html.twig', [
            'branch' => $branch,
            'update_form' => $updateForm,
            'status_form' => $statusForm,
        ], new Response(status: $status));
    }

    /**
     * @param FormInterface<AdminBranchCreateRequest> $form
     */
    private function renderCreateFailure(FormInterface $form, InstitutionOperationException $e): Response
    {
        if (InstitutionFailureReason::Unauthorized === $e->getReason()) {
            throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
        }
        if (InstitutionFailureReason::NotFound === $e->getReason()) {
            $form->get('institutionId')->addError(new FormError('Bağlı kurum bulunamadı.'));

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (InstitutionFailureReason::Conflict === $e->getReason()) {
            $form->get('code')->addError(new FormError('Bu kod bu kurumda zaten kullanılıyor.'));

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (InstitutionFailureReason::InvalidInput === $e->getReason()) {
            $message = $e->getMessage();
            if (str_contains($message, 'code')) {
                $form->get('code')->addError(new FormError('Kod harf, rakam ve kısa çizgi içerebilir.'));
            } else {
                $form->get('name')->addError(new FormError('Addan geçerli bir kayıt adı üretilemedi.'));
            }

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->flashBranchException($e);

        return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param FormInterface<AdminBranchUpdateRequest> $form
     */
    private function renderUpdateFailure(\App\Dto\AdminBranchDetailView $branch, FormInterface $form, InstitutionOperationException $e): Response
    {
        if (InstitutionFailureReason::Unauthorized === $e->getReason()) {
            throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
        }
        if (InstitutionFailureReason::NotFound === $e->getReason()) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }
        if (InstitutionFailureReason::Conflict === $e->getReason()) {
            $form->get('code')->addError(new FormError('Bu kod bu kurumda zaten kullanılıyor.'));

            return $this->renderDetail($branch, $form->createView(), $this->statusForm($branch), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (InstitutionFailureReason::InvalidInput === $e->getReason()) {
            $form->get('code')->addError(new FormError('Kod harf, rakam ve kısa çizgi içerebilir.'));

            return $this->renderDetail($branch, $form->createView(), $this->statusForm($branch), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->flashBranchException($e);

        return $this->redirectToRoute('app_admin_branch_detail', ['id' => $branch->id]);
    }

    private function flashBranchException(InstitutionOperationException $e): void
    {
        match ($e->getReason()) {
            InstitutionFailureReason::Unauthorized => throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.'),
            InstitutionFailureReason::NotFound => throw $this->createNotFoundException('Kayıt bulunamadı.'),
            InstitutionFailureReason::Conflict => $this->addFlash('error', 'Eşzamanlı işlem çakışması. Lütfen yenileyin.'),
            InstitutionFailureReason::InvalidTransition => $this->addFlash('error', 'İstenen durum geçişi geçerli değil.'),
            InstitutionFailureReason::InvalidInput => $this->addFlash('error', 'Geçersiz girdi.'),
            InstitutionFailureReason::InstitutionNotActive => $this->addFlash('error', 'Bağlı kurum aktif değilken kayıt aktifleştirilemez.'),
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
