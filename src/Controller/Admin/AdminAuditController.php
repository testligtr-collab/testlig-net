<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Exception\CommerceException;
use App\Security\AdminPermission;
use App\Service\Admin\AdminAuditReadModel;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminAuditController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminAuditReadModel $auditReadModel,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/denetim', name: 'app_admin_audit', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_AUDIT_VIEW)]
    public function __invoke(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE);

        try {
            $result = $this->auditReadModel->listEvents($this->requireActorId(), $page, $pageSize);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/audit/list.html.twig', [
            'result' => $result,
        ]);
    }
}
