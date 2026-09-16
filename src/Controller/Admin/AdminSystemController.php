<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Exception\CommerceException;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminSystemHealthReadModel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminSystemController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminSystemHealthReadModel $systemHealthReadModel,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/sistem', name: 'app_admin_system', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_SYSTEM_VIEW)]
    public function __invoke(): Response
    {
        try {
            $view = $this->systemHealthReadModel->getSystemHealth($this->requireActorId());
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/system.html.twig', [
            'system' => $view,
        ]);
    }
}
