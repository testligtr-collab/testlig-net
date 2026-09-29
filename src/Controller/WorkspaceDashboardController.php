<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Admin\AdminBaseController;
use App\Security\AdminAuthorization;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\ContentWorkspaceSummary;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only content-team home. It is not an alias of /yonetim.
 */
final class WorkspaceDashboardController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly ContentWorkspaceSummary $workspaceSummary,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/calisma-alani', name: 'app_workspace_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $actor = $this->requireActorUser();
        $canContent = $this->adminAuthorization->canViewLearningContentWorkspace($actor);
        $classrooms = $this->workspaceSummary->activeClassroomCount($actor);
        if (!$canContent && $classrooms < 1) {
            throw $this->createAccessDeniedException('Bu sayfa için yetkiniz yok.');
        }

        return $this->renderAdmin('admin/workspace_dashboard.html.twig', [
            'home' => $this->workspaceSummary->workspaceHome($actor),
        ]);
    }
}
