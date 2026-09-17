<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminUserRolesRequest;
use App\Dto\AdminUserStatusRequest;
use App\Entity\User;
use App\Enum\CommerceFailureReason;
use App\Enum\UserManagementFailureReason;
use App\Enum\UserRole;
use App\Exception\CommerceException;
use App\Exception\InvalidUserTransitionException;
use App\Form\AdminUserRolesFormType;
use App\Form\AdminUserStatusFormType;
use App\Repository\UserRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\Admin\AdminUserQuery;
use App\Service\UserGlobalRoleManager;
use App\Service\UserStatusManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminUserController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminUserQuery $userQuery,
        private readonly UserRepository $users,
        private readonly UserGlobalRoleManager $roleManager,
        private readonly UserStatusManager $statusManager,
        #[Autowire(service: 'limiter.admin_user_roles')]
        private readonly RateLimiterFactory $rolesLimiter,
        #[Autowire(service: 'limiter.admin_user_status')]
        private readonly RateLimiterFactory $statusLimiter,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/kullanicilar', name: 'app_admin_users', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function list(Request $request): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'status' => $request->query->getString('status'),
            'role' => $request->query->getString('role'),
            'verified' => $request->query->getString('verified'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];

        try {
            $result = $this->userQuery->listUsers($this->requireActorId(), $filters);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/users/list.html.twig', [
            'result' => $result,
            'filters' => $filters,
            'role_choices' => UserRole::cases(),
        ]);
    }

    #[Route('/yonetim/kullanicilar/{id}', name: 'app_admin_user_detail', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function detail(Uuid $id): Response
    {
        try {
            $user = $this->userQuery->getDetail($this->requireActorId(), $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        $rolesForm = null;
        $statusForm = null;
        if ($user->canManageRoles) {
            $dto = new AdminUserRolesRequest();
            $dto->roles = array_values(array_filter(
                $user->globalRoles,
                static fn (string $r): bool => UserRole::User->value !== $r,
            ));
            $rolesForm = $this->createForm(AdminUserRolesFormType::class, $dto, [
                'user_id' => $id,
                'assignable_roles' => $user->assignableRoleValues,
                'action' => $this->generateUrl('app_admin_user_roles', ['id' => $id]),
                'method' => 'POST',
            ])->createView();
        }
        if ($user->canManageStatus && [] !== $user->allowedStatusTargets) {
            $statusForm = $this->createForm(AdminUserStatusFormType::class, new AdminUserStatusRequest(), [
                'user_id' => $id,
                'allowed_targets' => $user->allowedStatusTargets,
                'action' => $this->generateUrl('app_admin_user_status', ['id' => $id]),
                'method' => 'POST',
            ])->createView();
        }

        return $this->renderAdmin('admin/users/detail.html.twig', [
            'user' => $user,
            'roles_form' => $rolesForm,
            'status_form' => $statusForm,
            'audit_href' => $this->isGranted(AdminPermission::ADMIN_AUDIT_VIEW)
                ? $this->generateUrl('app_admin_audit')
                : null,
        ]);
    }

    #[Route('/yonetim/kullanicilar/{id}/roller', name: 'app_admin_user_roles', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_MANAGE)]
    public function updateRoles(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();

        $payload = $request->request->all()['admin_user_roles'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $target = $this->users->findOneById($id);
        if (!$target instanceof User) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        try {
            $detail = $this->userQuery->getDetail($actorId, $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        $dto = new AdminUserRolesRequest();
        $form = $this->createForm(AdminUserRolesFormType::class, $dto, [
            'user_id' => $id,
            'assignable_roles' => $detail->assignableRoleValues,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            $this->addFlash('error', 'Rol formu geçersiz. Onay ve geçerli gerekçe gereklidir.');

            return $this->redirectToRoute('app_admin_user_detail', ['id' => $id]);
        }

        $this->consumeLimiter($this->rolesLimiter, $actorId, $id, 'roles');

        $roleEnums = [];
        foreach ($dto->roles as $roleValue) {
            $role = UserRole::tryFrom($roleValue);
            if (!$role instanceof UserRole || UserRole::User === $role) {
                continue;
            }
            if (!\in_array($roleValue, $detail->assignableRoleValues, true)) {
                $this->addFlash('error', 'Seçilen roller bu aktör için geçerli değil.');

                return $this->redirectToRoute('app_admin_user_detail', ['id' => $id]);
            }
            $roleEnums[] = $role;
        }

        try {
            $actor = $this->requireActorUser();
            $this->roleManager->replaceRoles($target, $roleEnums, $actor, $dto->reasonCode);
            $this->addFlash('success', 'Kullanıcı rolleri güncellendi.');
        } catch (InvalidUserTransitionException $e) {
            $this->flashUserManagementFailure($e);
        }

        $response = $this->redirectToRoute('app_admin_user_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/kullanicilar/{id}/durum', name: 'app_admin_user_status', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_MANAGE)]
    public function updateStatus(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();

        $payload = $request->request->all()['admin_user_status'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $target = $this->users->findOneById($id);
        if (!$target instanceof User) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        try {
            $detail = $this->userQuery->getDetail($actorId, $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        $dto = new AdminUserStatusRequest();
        $form = $this->createForm(AdminUserStatusFormType::class, $dto, [
            'user_id' => $id,
            'allowed_targets' => $detail->allowedStatusTargets,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            $this->addFlash('error', 'Durum formu geçersiz. Onay ve geçerli gerekçe gereklidir.');

            return $this->redirectToRoute('app_admin_user_detail', ['id' => $id]);
        }

        $this->consumeLimiter($this->statusLimiter, $actorId, $id, 'status');

        $actor = $this->requireActorUser();
        try {
            match ($dto->action) {
                AdminUserStatusRequest::ACTION_SUSPEND => $this->statusManager->suspend($target, $actor, $dto->reasonCode),
                AdminUserStatusRequest::ACTION_ARCHIVE => $this->statusManager->archive($target, $actor, $dto->reasonCode),
                AdminUserStatusRequest::ACTION_REACTIVATE => $this->statusManager->reactivate($target, $actor, $dto->reasonCode),
                default => throw InvalidUserTransitionException::forRole('Invalid status action.'),
            };
            $this->addFlash('success', 'Kullanıcı durumu güncellendi.');
        } catch (InvalidUserTransitionException $e) {
            $this->flashUserManagementFailure($e);
        }

        $response = $this->redirectToRoute('app_admin_user_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    private function consumeLimiter(RateLimiterFactory $factory, Uuid $actorId, Uuid $targetId, string $action): void
    {
        $limiter = $factory->create($actorId->toRfc4122().':'.$targetId->toRfc4122().':'.$action);
        $rateLimit = $limiter->consume(1);
        if (!$rateLimit->isAccepted()) {
            $seconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException(
                $seconds,
                'Çok fazla istek. Lütfen daha sonra tekrar deneyin.',
            );
        }
    }

    private function flashUserManagementFailure(InvalidUserTransitionException $e): void
    {
        $reason = $e->getReason();
        $message = match ($reason) {
            UserManagementFailureReason::ActorNotActive,
            UserManagementFailureReason::ActorNotVerified,
            UserManagementFailureReason::ActorNotAuthorized => 'Bu işlem için yetkiniz yok.',
            UserManagementFailureReason::SelfManagementForbidden => 'Kendi hesabınız üzerinde bu işlem yapılamaz.',
            UserManagementFailureReason::ProtectedSuperAdmin => 'Süper yönetici hesabı bu yoldan yönetilemez.',
            UserManagementFailureReason::TargetPrivilegeTooHigh => 'Hedef hesabın yetki seviyesi bu işlem için uygun değil.',
            UserManagementFailureReason::UserNotFound => 'Kayıt bulunamadı.',
            UserManagementFailureReason::Conflict => 'Eşzamanlı işlem çakışması; lütfen yenileyin.',
            UserManagementFailureReason::InvalidTransition => 'İstenen durum geçişi geçerli değil.',
            UserManagementFailureReason::InvalidInput => 'Geçersiz girdi.',
            default => 'İşlem tamamlanamadı.',
        };
        if (\in_array($reason, [
            UserManagementFailureReason::ActorNotAuthorized,
            UserManagementFailureReason::ActorNotActive,
            UserManagementFailureReason::ActorNotVerified,
        ], true)) {
            throw $this->createAccessDeniedException($message);
        }
        if (UserManagementFailureReason::UserNotFound === $reason) {
            throw $this->createNotFoundException($message);
        }
        $this->addFlash('error', $message);
    }
}
