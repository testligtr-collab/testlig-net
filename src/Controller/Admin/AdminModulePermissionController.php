<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\CommerceFailureReason;
use App\Enum\ModeratorModuleGrant;
use App\Exception\CommerceException;
use App\Exception\UserModulePermissionException;
use App\Security\AdminPermission;
use App\Service\Admin\AdminModulePermissionQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\UserModulePermissionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Direct moderator module grants. Global roles stay on the user detail screen.
 */
final class AdminModulePermissionController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminModulePermissionQuery $permissions,
        private readonly UserModulePermissionManager $manager,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/rol-yetkileri', name: 'app_admin_module_permissions', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_MODULE_PERMISSIONS_MANAGE)]
    public function list(Request $request): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];
        try {
            $result = $this->permissions->list($filters);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw new NotFoundHttpException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/module_permissions/list.html.twig', [
            'result' => $result,
            'filters' => $filters,
            'role_scopes' => $this->roleScopes(),
        ]);
    }

    #[Route('/yonetim/rol-yetkileri/{id}', name: 'app_admin_module_permission', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_MODULE_PERMISSIONS_MANAGE)]
    public function detail(Uuid $id): Response
    {
        $detail = $this->permissions->detail($id);
        if (null === $detail) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        return $this->renderAdmin('admin/module_permissions/detail.html.twig', [
            'detail' => $detail,
            'grants' => ModeratorModuleGrant::cases(),
        ]);
    }

    #[Route('/yonetim/rol-yetkileri/{id}', name: 'app_admin_module_permission_save', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_MODULE_PERMISSIONS_MANAGE)]
    public function save(Request $request, Uuid $id): Response
    {
        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('module_permissions_'.$id->toRfc4122(), $token)) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        $redirect = $this->redirectToRoute('app_admin_module_permission', ['id' => $id->toRfc4122()]);
        $this->applyNoStore($redirect);
        if (!$request->request->has('version')) {
            $this->addFlash('error', 'Seçilen izin kullanılamaz.');

            return $redirect;
        }
        $rawVersion = $request->request->get('version');
        if (!\is_string($rawVersion) || !ctype_digit($rawVersion)) {
            $this->addFlash('error', 'Seçilen izin kullanılamaz.');

            return $redirect;
        }
        $payload = $request->request->all();
        $requested = $payload['permissions'] ?? [];
        if (!\is_array($requested)) {
            $this->addFlash('error', 'Seçilen izin kullanılamaz.');

            return $redirect;
        }

        try {
            $changed = $this->manager->replace($this->requireActorId(), $id, (int) $rawVersion, $requested);
        } catch (UserModulePermissionException $e) {
            if ('unauthorized' === $e->reason()) {
                throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
            }
            $this->addFlash($this->flashLabel($e), $this->flashMessage($e));
            if ('ineligible' === $e->reason()) {
                $list = $this->redirectToRoute('app_admin_module_permissions');
                $this->applyNoStore($list);

                return $list;
            }

            return $redirect;
        }
        $this->addFlash('success', $changed ? 'Modül izinleri güncellendi.' : 'İzinlerde değişiklik yok.');

        return $redirect;
    }

    /**
     * @return list<array{label: string, scope: string}>
     */
    private function roleScopes(): array
    {
        return [
            [
                'label' => 'Moderatör',
                'scope' => 'Platform içerik, soru ve testi görür, inceler ve taslağa döndürür. Yayınlamaz. Yazma izni yalnız bu ekrandan eklenir.',
            ],
            [
                'label' => 'Yönetici',
                'scope' => 'İçerik, soru, test, kullanıcı ve kurum ekranlarını kullanır. Modül izni dağıtamaz.',
            ],
            [
                'label' => 'Süper Yönetici',
                'scope' => 'Bu ekranı kullanır. Ödeme, webhook, uzlaştırma, denetim ve operasyon devredilmez.',
            ],
            [
                'label' => 'Öğretmen, uzman öğretmen ve zümre başkanı',
                'scope' => 'Kendi içerik kuralları durur. Bu ekrandan modül izni almazlar.',
            ],
        ];
    }

    private function flashLabel(UserModulePermissionException $exception): string
    {
        return 'conflict' === $exception->reason() ? 'warning' : 'error';
    }

    private function flashMessage(UserModulePermissionException $exception): string
    {
        return match ($exception->reason()) {
            'conflict' => 'Bu hesap başka bir işlemde güncellendi. Sayfayı yenileyip yeniden deneyin.',
            'ineligible' => 'Bu hesabın modül izni yönetilemez.',
            'unauthorized' => 'Bu işlem için yetkiniz yok.',
            default => 'Seçilen izin kullanılamaz.',
        };
    }
}
