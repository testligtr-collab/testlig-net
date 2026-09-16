<?php

declare(strict_types=1);

namespace App\UiPreview\Controller;

use App\UiPreview\AdminPreviewView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminPreviewController extends AbstractController
{
    #[Route('/onizleme/admin', name: 'ui_preview_admin', methods: ['GET'])]
    public function __invoke(): Response
    {
        $view = AdminPreviewView::demo();

        return $this->render('ui_preview/admin.html.twig', [
            'view' => $view,
            'display_name' => $view->displayName,
            'avatar_initials' => 'AY',
            'panel_role_label' => 'Süper Yönetici',
            'nav_items' => $view->navItems,
            'mobile_nav' => $view->mobileNav,
            'notifications' => $view->notifications,
        ]);
    }
}
