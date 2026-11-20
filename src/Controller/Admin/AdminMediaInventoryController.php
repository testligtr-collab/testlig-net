<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Presentation\AdminMediaInventoryLabels;
use App\Repository\StoredMediaAssetRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SuperAdmin-only platform media inventory. Record metadata only; no provider I/O.
 */
final class AdminMediaInventoryController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly StoredMediaAssetRepository $assets,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/bolum/medya', name: 'app_admin_media_inventory', methods: ['GET'], priority: 10)]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function index(Request $request): Response
    {
        $total = $this->assets->countPlatformInventory();
        $pageSize = AdminPagination::DEFAULT_PAGE_SIZE;
        $lastPage = max(1, intdiv(max(0, $total - 1), $pageSize) + 1);
        $page = min($lastPage, AdminPagination::normalizePage($request->query->getInt('page', 1)));
        $rows = [];
        foreach ($this->assets->listPlatformInventory(AdminPagination::offset($page, $pageSize), $pageSize) as $row) {
            $rows[] = [
                'kind_label' => AdminMediaInventoryLabels::kind($row['kind']),
                'byte_size_label' => AdminMediaInventoryLabels::byteSize($row['byte_size']),
                'storage_label' => AdminMediaInventoryLabels::storageProvider($row['storage_provider']),
                'status_label' => AdminMediaInventoryLabels::status($row['status']),
                'status_tone' => AdminMediaInventoryLabels::statusTone($row['status']),
            ];
        }

        return $this->renderAdmin('admin/media/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }
}
