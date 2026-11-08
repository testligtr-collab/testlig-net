<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\NewsArticle;
use App\Enum\NewsArticleStatus;
use App\Enum\NewsFailureReason;
use App\Exception\NewsArticleException;
use App\Repository\NewsArticleRepository;
use App\Repository\NewsCategoryRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\News\NewsArticleManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/yonetim/haberler')]
final class AdminNewsController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly NewsArticleManager $news,
        private readonly NewsArticleRepository $articles,
        private readonly NewsCategoryRepository $categories,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('', name: 'app_admin_news', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function index(): Response
    {
        return $this->renderAdmin('admin/news/index.html.twig', [
            'articles' => $this->articles->findRecent(100),
            'categories' => $this->categories->findAllOrdered(),
        ]);
    }

    #[Route('/yeni', name: 'app_admin_news_new', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function new(): Response
    {
        return $this->renderForm(null, '', '', '', '');
    }

    #[Route('/kategoriler', name: 'app_admin_news_category_create', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function createCategory(Request $request): Response
    {
        $this->assertCsrf($request, 'news_category');
        try {
            $this->news->createCategory($this->requireActorUser(), $request->request->getString('name'));
            $this->addFlash('success', 'Kategori kaydedildi.');
        } catch (NewsArticleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_news');
    }

    #[Route('', name: 'app_admin_news_create', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, 'news_article');
        $title = $request->request->getString('title');
        $summary = $request->request->getString('summary');
        $body = $request->request->getString('body');
        $categoryId = $request->request->getString('category_id');
        try {
            $article = $this->news->createDraft(
                $this->requireActorUser(),
                $title,
                $summary,
                $body,
                $this->categoryUuid($categoryId),
            );
            $this->addFlash('success', 'Taslak haber kaydedildi.');

            return $this->redirectToRoute('app_admin_news_show', ['id' => $article->getId()->toRfc4122()]);
        } catch (NewsArticleException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->renderForm(null, $title, $summary, $body, $this->safeCategoryId($categoryId), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{id}/duzenle', name: 'app_admin_news_edit', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function edit(string $id): Response
    {
        $article = $this->article($id);
        if (NewsArticleStatus::Draft !== $article->getStatus()) {
            throw $this->createNotFoundException();
        }

        return $this->renderForm(
            $article,
            $article->getTitle(),
            $article->getSummary(),
            $article->getBody(),
            $article->getCategory()?->getId()->toRfc4122() ?? '',
        );
    }

    #[Route('/{id}', name: 'app_admin_news_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function show(string $id): Response
    {
        return $this->renderArticle($this->article($id));
    }

    #[Route('/{id}', name: 'app_admin_news_update', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function update(Request $request, string $id): Response
    {
        $this->assertCsrf($request, 'news_article_'.$id);
        $article = $this->article($id);
        $title = $request->request->getString('title');
        $summary = $request->request->getString('summary');
        $body = $request->request->getString('body');
        $categoryId = $request->request->getString('category_id');
        try {
            $this->news->updateDraft(
                $this->requireActorUser(),
                $article,
                $title,
                $summary,
                $body,
                $this->categoryUuid($categoryId),
            );
            $this->addFlash('success', 'Taslak güncellendi.');

            return $this->redirectToRoute('app_admin_news_show', ['id' => $id]);
        } catch (NewsArticleException $e) {
            $this->addFlash('error', $e->getMessage());
            if (\in_array($e->getReason(), [NewsFailureReason::InvalidInput, NewsFailureReason::NotFound], true)) {
                return $this->renderForm($article, $title, $summary, $body, $this->safeCategoryId($categoryId), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->redirectToRoute('app_admin_news_show', ['id' => $id]);
        }
    }

    #[Route('/{id}/incelemeye', name: 'app_admin_news_submit', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function submit(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'news_submit_'.$id, 'İncelemeye gönderildi.', fn (NewsArticle $article) => $this->news->submitForReview($this->requireActorUser(), $article));
    }

    #[Route('/{id}/taslaga', name: 'app_admin_news_draft', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function draft(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'news_draft_'.$id, 'Haber taslağa alındı.', fn (NewsArticle $article) => $this->news->returnToDraft($this->requireActorUser(), $article));
    }

    #[Route('/{id}/yayimla', name: 'app_admin_news_publish', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function publish(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'news_publish_'.$id, 'Haber yayımlandı.', fn (NewsArticle $article) => $this->news->publish($this->requireActorUser(), $article));
    }

    #[Route('/{id}/arsivle', name: 'app_admin_news_archive', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_NEWS_MANAGE)]
    public function archive(Request $request, string $id): Response
    {
        return $this->transition($request, $id, 'news_archive_'.$id, 'Haber arşivlendi.', fn (NewsArticle $article) => $this->news->archive($this->requireActorUser(), $article));
    }

    /**
     * @param \Closure(NewsArticle): void $action
     */
    private function transition(Request $request, string $id, string $tokenId, string $success, \Closure $action): Response
    {
        $this->assertCsrf($request, $tokenId);
        $article = $this->article($id);
        try {
            $action($article);
            $this->addFlash('success', $success);
        } catch (NewsArticleException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_news_show', ['id' => $id]);
    }

    private function article(string $id): NewsArticle
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        $article = $this->articles->findOneById(Uuid::fromString($id));
        if (!$article instanceof NewsArticle) {
            throw $this->createNotFoundException();
        }

        return $article;
    }

    private function categoryUuid(string $raw): ?Uuid
    {
        if ('' === $raw) {
            return null;
        }
        if (!Uuid::isValid($raw)) {
            throw NewsArticleException::invalidInput();
        }

        return Uuid::fromString($raw);
    }

    private function safeCategoryId(string $raw): string
    {
        if ('' === $raw || !Uuid::isValid($raw)) {
            return '';
        }
        $category = $this->categories->findOneById(Uuid::fromString($raw));

        return null === $category ? '' : $raw;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
    }

    private function renderForm(?NewsArticle $article, string $title, string $summary, string $body, string $categoryId, int $status = 200): Response
    {
        return $this->renderAdmin('admin/news/form.html.twig', [
            'article' => $article,
            'categories' => $this->categories->findAllOrdered(),
            'title_value' => $title,
            'summary_value' => $summary,
            'body_value' => $body,
            'category_id' => $categoryId,
        ], new Response(status: $status));
    }

    private function renderArticle(NewsArticle $article): Response
    {
        $canPublish = !$article->blocksPublishBy($this->requireActorUser());

        return $this->renderAdmin('admin/news/show.html.twig', [
            'article' => $article,
            'can_publish' => $canPublish,
        ]);
    }
}
