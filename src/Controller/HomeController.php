<?php

declare(strict_types=1);

namespace App\Controller;

use App\Homepage\HomepageView;
use App\Repository\NewsArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly NewsArticleRepository $articles,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig', [
            'view' => HomepageView::demo(),
            'news_items' => $this->articles->findPublished(3),
        ]);
    }
}
