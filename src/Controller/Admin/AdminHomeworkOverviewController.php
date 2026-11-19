<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\HomeworkAssignmentRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminPagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminHomeworkOverviewController extends AdminBaseController
{
    #[Route('/yonetim/bolum/odevler', name: 'app_admin_homework_overview', methods: ['GET'], priority: 10)]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function index(Request $request, HomeworkAssignmentRepository $assignments): Response
    {
        $total = $assignments->count([]);
        $pageSize = AdminPagination::DEFAULT_PAGE_SIZE;
        $lastPage = max(1, intdiv(max(0, $total - 1), $pageSize) + 1);
        $page = min($lastPage, AdminPagination::normalizePage($request->query->getInt('page', 1)));

        return $this->renderAdmin('admin/homework/index.html.twig', [
            'rows' => $assignments->adminOverview(AdminPagination::offset($page, $pageSize), $pageSize),
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }
}
