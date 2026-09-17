<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminInstitutionCreateRequest;
use App\Dto\AdminInstitutionStatusRequest;
use App\Dto\AdminMembershipRoleRequest;
use App\Dto\AdminMembershipStatusRequest;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\CommerceFailureReason;
use App\Enum\InstitutionFailureReason;
use App\Enum\InstitutionMembershipFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Exception\CommerceException;
use App\Exception\InstitutionMembershipException;
use App\Exception\InstitutionOperationException;
use App\Form\AdminInstitutionCreateFormType;
use App\Form\AdminInstitutionStatusFormType;
use App\Form\AdminMembershipRoleFormType;
use App\Form\AdminMembershipStatusFormType;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminInstitutionQuery;
use App\Service\Admin\AdminMembershipQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminInstitutionController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminInstitutionQuery $institutionQuery,
        private readonly AdminMembershipQuery $membershipQuery,
        private readonly InstitutionRepository $institutions,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly InstitutionCreator $institutionCreator,
        private readonly InstitutionStatusManager $institutionStatusManager,
        private readonly InstitutionMembershipManager $membershipManager,
        #[Autowire(service: 'limiter.admin_institution_create')]
        private readonly RateLimiterFactory $createLimiter,
        #[Autowire(service: 'limiter.admin_institution_status')]
        private readonly RateLimiterFactory $institutionStatusLimiter,
        #[Autowire(service: 'limiter.admin_membership_mutate')]
        private readonly RateLimiterFactory $membershipLimiter,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/kurumlar', name: 'app_admin_institutions', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_INSTITUTIONS_VIEW)]
    public function list(Request $request): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'type' => $request->query->getString('type'),
            'status' => $request->query->getString('status'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];

        try {
            $result = $this->institutionQuery->listInstitutions($this->requireActorId(), $filters);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/institutions/list.html.twig', [
            'result' => $result,
            'filters' => $filters,
            'can_create' => $this->isGranted(AdminPermission::ADMIN_INSTITUTIONS_CREATE),
        ]);
    }

    #[Route('/yonetim/kurumlar/yeni', name: 'app_admin_institution_new', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_INSTITUTIONS_CREATE)]
    public function newForm(): Response
    {
        $form = $this->createForm(AdminInstitutionCreateFormType::class, new AdminInstitutionCreateRequest(), [
            'action' => $this->generateUrl('app_admin_institution_create'),
            'method' => 'POST',
        ]);

        return $this->renderAdmin('admin/institutions/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/yonetim/kurumlar', name: 'app_admin_institution_create', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_INSTITUTIONS_CREATE)]
    public function create(Request $request): Response
    {
        $actorId = $this->requireActorId();
        $limiter = $this->createLimiter->create($actorId->toRfc4122().':create');
        $rateLimit = $limiter->consume(1);
        if (!$rateLimit->isAccepted()) {
            $seconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }

        $payload = $request->request->all()['admin_institution_create'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $dto = new AdminInstitutionCreateRequest();
        $form = $this->createForm(AdminInstitutionCreateFormType::class, $dto);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderAdmin('admin/institutions/new.html.twig', [
                'form' => $form->createView(),
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if (!Uuid::isValid($dto->ownerUserId)) {
            $this->addFlash('error', 'Geçerli bir sahip kullanıcı UUID’si girin.');

            return $this->redirectToRoute('app_admin_institution_new');
        }

        $ownerId = Uuid::fromString($dto->ownerUserId);
        $owner = $this->users->findOneById($ownerId);
        if (!$owner instanceof User) {
            $this->addFlash('error', 'Sahip kullanıcı bulunamadı.');

            return $this->redirectToRoute('app_admin_institution_new');
        }

        try {
            $institution = $this->institutionCreator->create(
                $this->requireActorUser(),
                $owner,
                $dto->name,
                $dto->type ?? throw InstitutionOperationException::invalidInput(),
                $dto->reasonCode,
            );
            $this->addFlash('success', 'Kurum oluşturuldu.');
            $response = $this->redirectToRoute('app_admin_institution_detail', ['id' => $institution->getId()]);
            $this->applyNoStore($response);

            return $response;
        } catch (InstitutionOperationException $e) {
            $this->handleInstitutionException($e);

            return $this->redirectToRoute('app_admin_institution_new');
        }
    }

    #[Route('/yonetim/kurumlar/{id}', name: 'app_admin_institution_detail', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_INSTITUTIONS_VIEW)]
    public function detail(Uuid $id): Response
    {
        try {
            $institution = $this->institutionQuery->getDetail($this->requireActorId(), $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        $statusForm = null;
        if ($institution->canManageStatus && [] !== $institution->allowedStatusTargets) {
            $statusForm = $this->createForm(AdminInstitutionStatusFormType::class, new AdminInstitutionStatusRequest(), [
                'institution_id' => $id,
                'allowed_targets' => $institution->allowedStatusTargets,
                'action' => $this->generateUrl('app_admin_institution_status', ['id' => $id]),
                'method' => 'POST',
            ])->createView();
        }

        return $this->renderAdmin('admin/institutions/detail.html.twig', [
            'institution' => $institution,
            'status_form' => $statusForm,
            'audit_href' => $this->isGranted(AdminPermission::ADMIN_AUDIT_VIEW)
                ? $this->generateUrl('app_admin_audit')
                : null,
        ]);
    }

    #[Route('/yonetim/kurumlar/{id}/durum', name: 'app_admin_institution_status', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_INSTITUTIONS_MANAGE)]
    public function updateStatus(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();
        $limiter = $this->institutionStatusLimiter->create($actorId->toRfc4122().':'.$id->toRfc4122().':status');
        $rateLimit = $limiter->consume(1);
        if (!$rateLimit->isAccepted()) {
            $seconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }

        $payload = $request->request->all()['admin_institution_status'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $entity = $this->institutions->findOneById($id);
        if (!$entity instanceof Institution) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        try {
            $detail = $this->institutionQuery->getDetail($actorId, $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        $dto = new AdminInstitutionStatusRequest();
        $form = $this->createForm(AdminInstitutionStatusFormType::class, $dto, [
            'institution_id' => $id,
            'allowed_targets' => $detail->allowedStatusTargets,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            $this->addFlash('error', 'Durum formu geçersiz. Onay ve geçerli gerekçe gereklidir.');

            return $this->redirectToRoute('app_admin_institution_detail', ['id' => $id]);
        }

        try {
            $actor = $this->requireActorUser();
            match ($dto->action) {
                AdminInstitutionStatusRequest::ACTION_ACTIVATE => $this->institutionStatusManager->activate($entity, $actor, $dto->reasonCode),
                AdminInstitutionStatusRequest::ACTION_SUSPEND => $this->institutionStatusManager->suspend($entity, $actor, $dto->reasonCode),
                AdminInstitutionStatusRequest::ACTION_ARCHIVE => $this->institutionStatusManager->archive($entity, $actor, $dto->reasonCode),
                default => throw InstitutionOperationException::invalidInput(),
            };
            $this->addFlash('success', 'Kurum durumu güncellendi.');
        } catch (InstitutionOperationException $e) {
            $this->handleInstitutionException($e);
        }

        $response = $this->redirectToRoute('app_admin_institution_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/kurumlar/{id}/uyeler', name: 'app_admin_institution_members', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_MEMBERSHIPS_VIEW)]
    public function members(Request $request, Uuid $id): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'role' => $request->query->getString('role'),
            'status' => $request->query->getString('status'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];

        try {
            $payload = $this->membershipQuery->listForInstitution($this->requireActorId(), $id, $filters);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        $institution = $payload['institution'];
        $result = $payload['result'];

        return $this->renderAdmin('admin/institutions/members.html.twig', [
            'institution_id' => $id,
            'institution_name' => $institution->getName(),
            'institution_short_ref' => substr($id->toRfc4122(), 0, 8),
            'result' => $result,
            'filters' => $filters,
            'role_choices' => InstitutionMembershipRole::cases(),
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_MEMBERSHIPS_MANAGE),
        ]);
    }

    #[Route(
        '/yonetim/kurumlar/{institutionId}/uyeler/{membershipId}/rol',
        name: 'app_admin_membership_role',
        methods: ['POST'],
        requirements: ['institutionId' => '[0-9a-fA-F-]{36}', 'membershipId' => '[0-9a-fA-F-]{36}'],
    )]
    #[IsGranted(AdminPermission::ADMIN_MEMBERSHIPS_MANAGE)]
    public function updateMembershipRole(Request $request, Uuid $institutionId, Uuid $membershipId): Response
    {
        $actorId = $this->requireActorId();
        $this->consumeMembershipLimiter($actorId, $membershipId, 'role');

        $payload = $request->request->all()['admin_membership_role'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $membership = $this->requireMembershipInInstitution($institutionId, $membershipId);

        $dto = new AdminMembershipRoleRequest();
        $form = $this->createForm(AdminMembershipRoleFormType::class, $dto, [
            'membership_id' => $membershipId,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            $this->addFlash('error', 'Rol formu geçersiz.');

            return $this->redirectToRoute('app_admin_institution_members', ['id' => $institutionId]);
        }

        try {
            $this->membershipManager->changeRole(
                $membership,
                $this->requireActorUser(),
                $dto->role ?? throw InstitutionMembershipException::invalidInput(),
                $dto->reasonCode,
            );
            $this->addFlash('success', 'Üyelik rolü güncellendi.');
        } catch (InstitutionMembershipException $e) {
            $this->handleMembershipException($e);
        }

        $response = $this->redirectToRoute('app_admin_institution_members', ['id' => $institutionId]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route(
        '/yonetim/kurumlar/{institutionId}/uyeler/{membershipId}/durum',
        name: 'app_admin_membership_status',
        methods: ['POST'],
        requirements: ['institutionId' => '[0-9a-fA-F-]{36}', 'membershipId' => '[0-9a-fA-F-]{36}'],
    )]
    #[IsGranted(AdminPermission::ADMIN_MEMBERSHIPS_MANAGE)]
    public function updateMembershipStatus(Request $request, Uuid $institutionId, Uuid $membershipId): Response
    {
        $actorId = $this->requireActorId();
        $this->consumeMembershipLimiter($actorId, $membershipId, 'status');

        $payload = $request->request->all()['admin_membership_status'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $membership = $this->requireMembershipInInstitution($institutionId, $membershipId);

        $allowed = [];
        if ('active' === $membership->getStatus()->value) {
            $allowed = [
                AdminMembershipStatusRequest::ACTION_SUSPEND,
                AdminMembershipStatusRequest::ACTION_END,
            ];
        } elseif ('suspended' === $membership->getStatus()->value) {
            $allowed = [
                AdminMembershipStatusRequest::ACTION_REACTIVATE,
                AdminMembershipStatusRequest::ACTION_END,
            ];
        }

        $dto = new AdminMembershipStatusRequest();
        $form = $this->createForm(AdminMembershipStatusFormType::class, $dto, [
            'membership_id' => $membershipId,
            'allowed_actions' => $allowed,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            $this->addFlash('error', 'Durum formu geçersiz.');

            return $this->redirectToRoute('app_admin_institution_members', ['id' => $institutionId]);
        }

        if (!\in_array($dto->action, $allowed, true)) {
            $this->addFlash('error', 'Seçilen işlem bu üyelik için geçerli değil.');

            return $this->redirectToRoute('app_admin_institution_members', ['id' => $institutionId]);
        }

        try {
            $actor = $this->requireActorUser();
            if (AdminMembershipStatusRequest::ACTION_SUSPEND === $dto->action) {
                $this->membershipManager->suspend($membership, $actor, $dto->reasonCode);
            } elseif (AdminMembershipStatusRequest::ACTION_REACTIVATE === $dto->action) {
                $this->membershipManager->reactivate($membership, $actor, $dto->reasonCode);
            } else {
                $this->membershipManager->endMembership($membership, $actor, $dto->reasonCode);
            }
            $this->addFlash('success', 'Üyelik durumu güncellendi.');
        } catch (InstitutionMembershipException $e) {
            $this->handleMembershipException($e);
        }

        $response = $this->redirectToRoute('app_admin_institution_members', ['id' => $institutionId]);
        $this->applyNoStore($response);

        return $response;
    }

    private function requireMembershipInInstitution(Uuid $institutionId, Uuid $membershipId): InstitutionMembership
    {
        $membership = $this->memberships->findOneById($membershipId);
        if (!$membership instanceof InstitutionMembership) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }
        if (!$membership->getInstitution()->getId()->equals($institutionId)) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        return $membership;
    }

    private function consumeMembershipLimiter(Uuid $actorId, Uuid $membershipId, string $action): void
    {
        $limiter = $this->membershipLimiter->create($actorId->toRfc4122().':'.$membershipId->toRfc4122().':'.$action);
        $rateLimit = $limiter->consume(1);
        if (!$rateLimit->isAccepted()) {
            $seconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }
    }

    private function handleInstitutionException(InstitutionOperationException $e): void
    {
        match ($e->getReason()) {
            InstitutionFailureReason::Unauthorized => throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.'),
            InstitutionFailureReason::NotFound => throw $this->createNotFoundException('Kayıt bulunamadı.'),
            InstitutionFailureReason::Conflict => $this->addFlash('error', 'Çakışma: slug veya ad zaten kullanılıyor olabilir.'),
            InstitutionFailureReason::InvalidTransition => $this->addFlash('error', 'İstenen kurum durum geçişi geçerli değil.'),
            InstitutionFailureReason::InvalidInput => $this->addFlash('error', 'Geçersiz girdi.'),
            InstitutionFailureReason::InstitutionNotActive => $this->addFlash('error', 'Kurum bu işlem için uygun durumda değil.'),
        };
    }

    private function handleMembershipException(InstitutionMembershipException $e): void
    {
        match ($e->getReason()) {
            InstitutionMembershipFailureReason::Unauthorized => throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.'),
            InstitutionMembershipFailureReason::NotFound,
            InstitutionMembershipFailureReason::CrossInstitution => throw $this->createNotFoundException('Kayıt bulunamadı.'),
            InstitutionMembershipFailureReason::LastOwnerProtected => $this->addFlash('error', 'Son aktif sahip korunuyor; işlem uygulanmadı.'),
            InstitutionMembershipFailureReason::Conflict,
            InstitutionMembershipFailureReason::DuplicateMembership => $this->addFlash('warning', 'Eşzamanlı işlem çakışması; lütfen yenileyin.'),
            InstitutionMembershipFailureReason::InvalidTransition => $this->addFlash('error', 'Üyelik durum geçişi geçerli değil.'),
            InstitutionMembershipFailureReason::InvalidInput => $this->addFlash('error', 'Geçersiz girdi.'),
            InstitutionMembershipFailureReason::InstitutionNotOperable => $this->addFlash('error', 'Arşivlenmiş veya pasif kurumda üyelik yönetimi kapalı.'),
            InstitutionMembershipFailureReason::OwnerRoleRestricted => $this->addFlash('error', 'Owner rolü bu yoldan atanamaz veya değiştirilemez.'),
            InstitutionMembershipFailureReason::ActiveClassroomLinkConflict => $this->addFlash('error', 'Aktif sınıf/kurs bağlantısı nedeniyle işlem yapılamadı.'),
        };
    }
}
