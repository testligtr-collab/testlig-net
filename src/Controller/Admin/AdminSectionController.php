<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminSectionCatalog;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminSectionController extends AdminBaseController
{
    public function __construct(AdminNavBuilder $adminNavBuilder)
    {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/bolum/{key}', name: 'app_admin_section', methods: ['GET'], requirements: ['key' => '[a-z0-9-]{1,40}'])]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function show(string $key): Response
    {
        $section = AdminSectionCatalog::find($key);
        if (null === $section) {
            throw $this->createNotFoundException();
        }

        return $this->renderAdmin('admin/section/show.html.twig', [
            'section' => $section,
        ]);
    }
}
