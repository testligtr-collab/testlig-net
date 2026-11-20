<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Presentation\AdminDocumentsInventoryLabels;
use App\Repository\LearningDocumentAssetRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SuperAdmin-only platform documents inventory via content revision links.
 */
final class AdminDocumentsInventoryController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly LearningDocumentAssetRepository $documents,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/bolum/dokumanlar', name: 'app_admin_documents_inventory', methods: ['GET'], priority: 10)]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function index(Request $request): Response
    {
        $total = $this->documents->countPlatformLinkedForAdminDocuments();
        $pageSize = AdminPagination::DEFAULT_PAGE_SIZE;
        $lastPage = max(1, intdiv(max(0, $total - 1), $pageSize) + 1);
        $page = min($lastPage, AdminPagination::normalizePage($request->query->getInt('page', 1)));
        $rows = [];
        foreach ($this->documents->listPlatformLinkedForAdminDocuments(AdminPagination::offset($page, $pageSize), $pageSize) as $row) {
            $rows[] = [
                'content_id' => $row['content_id'],
                'content_title' => $row['content_title'],
                'kind_label' => AdminDocumentsInventoryLabels::kind($row['media_type']),
                'byte_size_label' => AdminDocumentsInventoryLabels::byteSize($row['byte_size']),
                'status_label' => AdminDocumentsInventoryLabels::status($row['status']),
                'status_tone' => AdminDocumentsInventoryLabels::statusTone($row['status']),
            ];
        }

        return $this->renderAdmin('admin/documents/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }
}
