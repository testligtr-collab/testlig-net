<?php

declare(strict_types=1);

namespace App\UiPreview\Controller;

use App\Homepage\HomepageView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomepagePreviewController extends AbstractController
{
    #[Route('/onizleme/anasayfa-yeni', name: 'ui_preview_homepage_new', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('ui_preview/homepage_new.html.twig', [
            'view' => HomepageView::demo(),
        ]);
    }
}
