<?php

declare(strict_types=1);

namespace App\UiPreview\Controller;

use App\UiPreview\ParentPreviewView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ParentPreviewController extends AbstractController
{
    #[Route('/onizleme/veli', name: 'ui_preview_parent', methods: ['GET'])]
    public function __invoke(): Response
    {
        $view = ParentPreviewView::demo();

        return $this->render('ui_preview/parent.html.twig', [
            'view' => $view,
            'display_name' => 'Ece’nin Velisi',
            'avatar_initials' => 'EV',
            'panel_role_label' => 'Veli',
            'nav_items' => $view->navItems,
            'mobile_nav' => $view->mobileNav,
            'notifications' => $view->notifications,
        ]);
    }
}
