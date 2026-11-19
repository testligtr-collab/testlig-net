<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AssessmentRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SuperAdmin-only read directory of platform assessments that open the existing result screen.
 */
final class AdminReportsOverviewController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AssessmentRepository $assessments,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/bolum/raporlar', name: 'app_admin_reports_overview', methods: ['GET'], priority: 10)]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function index(Request $request): Response
    {
        $total = $this->assessments->countPlatformForAdminReports();
        $pageSize = AdminPagination::DEFAULT_PAGE_SIZE;
        $lastPage = max(1, intdiv(max(0, $total - 1), $pageSize) + 1);
        $page = min($lastPage, AdminPagination::normalizePage($request->query->getInt('page', 1)));
        $rows = [];
        foreach ($this->assessments->listPlatformForAdminReports(AdminPagination::offset($page, $pageSize), $pageSize) as $row) {
            $title = '' !== $row['title'] ? $row['title'] : 'Başlıksız test';
            $rows[] = [
                'id' => $row['id'],
                'title' => $title,
            ];
        }

        return $this->renderAdmin('admin/reports/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }
}
