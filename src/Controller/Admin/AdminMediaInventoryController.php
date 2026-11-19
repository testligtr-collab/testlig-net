<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaStorageProvider;
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
            $kind = $this->enumString($row['kind'] ?? null);
            $provider = $this->enumString($row['storage_provider'] ?? null);
            $status = $this->enumString($row['status'] ?? null);
            $bytes = $row['byte_size'] ?? 0;
            $byteSize = \is_int($bytes) ? $bytes : (int) $bytes;
            $rows[] = [
                'kind_label' => AdminMediaInventoryLabels::kind($kind),
                'byte_size_label' => AdminMediaInventoryLabels::byteSize($byteSize),
                'storage_label' => AdminMediaInventoryLabels::storageProvider($provider),
                'status_label' => AdminMediaInventoryLabels::status($status),
                'status_tone' => AdminMediaInventoryLabels::statusTone($status),
            ];
        }

        return $this->renderAdmin('admin/media/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }

    private function enumString(mixed $value): string
    {
        if ($value instanceof StoredMediaAssetKind
            || $value instanceof StoredMediaAssetStatus
            || $value instanceof StoredMediaStorageProvider) {
            return $value->value;
        }

        return \is_string($value) ? $value : '';
    }
}
