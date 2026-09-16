<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Exception\CommerceException;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\Admin\AdminReconciliationQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminReconciliationController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminReconciliationQuery $reconciliationQuery,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/uzlastirma', name: 'app_admin_reconciliations', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_PAYMENT_OPS)]
    public function list(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE);

        try {
            $result = $this->reconciliationQuery->listRuns($this->requireActorId(), $page, $pageSize);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/reconciliations/list.html.twig', [
            'result' => $result,
        ]);
    }

    #[Route('/yonetim/uzlastirma/{runId}', name: 'app_admin_reconciliation_detail', methods: ['GET'], requirements: ['runId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_PAYMENT_OPS)]
    public function detail(Request $request, Uuid $runId): Response
    {
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE);

        try {
            $run = $this->reconciliationQuery->getRunDetail($this->requireActorId(), $runId);
            $discrepancies = $this->reconciliationQuery->getDiscrepancies(
                $this->requireActorId(),
                $runId,
                $page,
                $pageSize,
            );
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/reconciliations/detail.html.twig', [
            'run' => $run,
            'discrepancies' => $discrepancies,
        ]);
    }
}
