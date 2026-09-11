<?php

declare(strict_types=1);

namespace App\UiPreview\Controller;

use App\UiPreview\TeacherPreviewView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TeacherPreviewController extends AbstractController
{
    #[Route('/onizleme/ogretmen', name: 'ui_preview_teacher', methods: ['GET'])]
    public function __invoke(): Response
    {
        $view = TeacherPreviewView::demo();

        return $this->render('ui_preview/teacher.html.twig', [
            'view' => $view,
            'display_name' => $view->displayName,
            'avatar_initials' => 'DÖ',
            'panel_role_label' => 'Öğretmen',
            'nav_items' => $view->navItems,
            'mobile_nav' => $view->mobileNav,
            'notifications' => $view->notifications,
        ]);
    }
}
