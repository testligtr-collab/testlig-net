<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Exception\CommerceException;
use App\Security\AdminPermission;
use App\Service\Admin\AdminDashboardReadModel;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\ContentWorkspaceSummary;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminDashboardController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminDashboardReadModel $dashboardReadModel,
        private readonly ContentWorkspaceSummary $workspaceSummary,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim', name: 'app_admin_dashboard', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_SHELL_ACCESS)]
    public function __invoke(): Response
    {
        try {
            $view = $this->dashboardReadModel->getDashboard($this->requireActorId());
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        $user = $this->getUser();

        return $this->renderAdmin('admin/dashboard.html.twig', [
            'dashboard' => $view,
            'workspace' => $user instanceof User ? $this->workspaceSummary->forActor($user) : null,
        ]);
    }
}
