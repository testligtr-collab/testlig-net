<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Presentation\AdminVideosInventoryLabels;
use App\Repository\LearningContentRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SuperAdmin-only platform videos inventory via content revision video blocks.
 */
final class AdminVideosInventoryController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly LearningContentRepository $contents,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/bolum/videolar', name: 'app_admin_videos_inventory', methods: ['GET'], priority: 10)]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function index(Request $request): Response
    {
        $total = $this->contents->countPlatformLinkedVideosForAdmin();
        $pageSize = AdminPagination::DEFAULT_PAGE_SIZE;
        $lastPage = max(1, intdiv(max(0, $total - 1), $pageSize) + 1);
        $page = min($lastPage, AdminPagination::normalizePage($request->query->getInt('page', 1)));
        $rows = [];
        foreach ($this->contents->listPlatformLinkedVideosForAdmin(AdminPagination::offset($page, $pageSize), $pageSize) as $row) {
            $rows[] = [
                'content_id' => $row['content_id'],
                'content_title' => $row['content_title'],
                'provider_label' => AdminVideosInventoryLabels::provider($row['provider']),
            ];
        }

        return $this->renderAdmin('admin/videos/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }
}
