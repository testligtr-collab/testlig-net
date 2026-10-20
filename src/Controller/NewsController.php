<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NewsArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewsController extends AbstractController
{
    public function __construct(
        private readonly NewsArticleRepository $articles,
    ) {
    }

    #[Route('/haberler', name: 'app_news_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('news/index.html.twig', [
            'articles' => $this->articles->findPublished(50),
        ]);
    }

    #[Route('/haberler/{slug}', name: 'app_news_show', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]{1,180}'])]
    public function show(string $slug): Response
    {
        $article = $this->articles->findPublishedBySlug($slug);
        if (null === $article) {
            throw $this->createNotFoundException();
        }

        return $this->render('news/show.html.twig', [
            'article' => $article,
        ]);
    }
}
