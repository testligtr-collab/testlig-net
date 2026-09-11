<?php

declare(strict_types=1);

namespace App\UiPreview\Controller;

use App\UiPreview\StudentPreviewView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StudentPreviewController extends AbstractController
{
    #[Route('/onizleme/ogrenci', name: 'ui_preview_student', methods: ['GET'])]
    public function __invoke(): Response
    {
        $view = StudentPreviewView::demo();

        return $this->render('ui_preview/student.html.twig', [
            'view' => $view,
            'display_name' => $view->displayName,
            'avatar_initials' => 'EY',
            'panel_role_label' => 'Öğrenci',
            'nav_items' => $view->navItems,
            'mobile_nav' => $view->mobileNav,
            'notifications' => $view->notifications,
        ]);
    }
}
