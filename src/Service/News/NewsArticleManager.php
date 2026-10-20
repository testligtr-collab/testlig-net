<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Entity\NewsArticle;
use App\Entity\NewsCategory;
use App\Entity\User;
use App\Exception\NewsArticleException;
use App\Repository\NewsArticleRepository;
use App\Repository\NewsCategoryRepository;
use App\Security\AdminAuthorization;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class NewsArticleManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NewsArticleRepository $articles,
        private readonly NewsCategoryRepository $categories,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createCategory(User $actor, string $name): NewsCategory
    {
        $this->assertManager($actor);
        $name = NewsText::plain($name, 2, 80, false);
        $slug = $this->uniqueCategorySlug(NewsText::slug($name, 'kategori'));
        $category = NewsCategory::create($name, $slug, $actor, $this->clock->now());
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function createDraft(User $actor, string $title, string $summary, string $body, ?Uuid $categoryId): NewsArticle
    {
        $this->assertManager($actor);
        [$title, $summary, $body] = $this->fields($title, $summary, $body);
        $category = $this->optionalCategory($categoryId);
        $article = NewsArticle::createDraft(
            $actor,
            $title,
            $this->uniqueArticleSlug(NewsText::slug($title, 'haber')),
            $summary,
            $body,
            $category,
            $this->clock->now(),
        );
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    public function updateDraft(User $actor, NewsArticle $article, string $title, string $summary, string $body, ?Uuid $categoryId): void
    {
        $this->assertManager($actor);
        [$title, $summary, $body] = $this->fields($title, $summary, $body);
        $article->reviseDraft($title, $summary, $body, $this->optionalCategory($categoryId), $this->clock->now());
        $this->entityManager->flush();
    }

    public function submitForReview(User $actor, NewsArticle $article): void
    {
        $this->assertManager($actor);
        $article->markSubmitted($actor, $this->clock->now());
        $this->entityManager->flush();
    }

    public function returnToDraft(User $actor, NewsArticle $article): void
    {
        $this->assertManager($actor);
        $article->markReturnedToDraft($this->clock->now());
        $this->entityManager->flush();
    }

    public function publish(User $actor, NewsArticle $article): void
    {
        $this->assertManager($actor);
        if ($article->getAuthor()->getId()->equals($actor->getId())) {
            throw NewsArticleException::separationOfDuties();
        }
        $submittedBy = $article->getSubmittedBy();
        if ($submittedBy instanceof User && $submittedBy->getId()->equals($actor->getId())) {
            throw NewsArticleException::separationOfDuties();
        }
        $article->markPublished($actor, $this->clock->now());
        $this->entityManager->flush();
    }

    public function archive(User $actor, NewsArticle $article): void
    {
        $this->assertManager($actor);
        $article->markArchived($this->clock->now());
        $this->entityManager->flush();
    }

    private function assertManager(User $actor): void
    {
        if (!$this->adminAuthorization->canManageNews($actor)) {
            throw NewsArticleException::unauthorized();
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function fields(string $title, string $summary, string $body): array
    {
        return [
            NewsText::plain($title, 3, 180, false),
            NewsText::plain($summary, 10, 500, false),
            NewsText::plain($body, 20, 20000, true),
        ];
    }

    private function optionalCategory(?Uuid $categoryId): ?NewsCategory
    {
        if (!$categoryId instanceof Uuid) {
            return null;
        }
        $category = $this->categories->findOneById($categoryId);
        if (!$category instanceof NewsCategory) {
            throw NewsArticleException::notFound();
        }

        return $category;
    }

    private function uniqueCategorySlug(string $base): string
    {
        return $this->uniqueSlug($base, fn (string $slug): bool => null !== $this->categories->findOneBy(['slug' => $slug]));
    }

    private function uniqueArticleSlug(string $base): string
    {
        return $this->uniqueSlug($base, fn (string $slug): bool => $this->articles->findOneBySlug($slug) instanceof NewsArticle);
    }

    /**
     * @param callable(string): bool $taken
     */
    private function uniqueSlug(string $base, callable $taken): string
    {
        $slug = $base;
        $suffix = 2;
        while ($taken($slug)) {
            $slug = mb_substr($base, 0, 70).'-'.$suffix;
            ++$suffix;
            if ($suffix > 50) {
                throw NewsArticleException::conflict();
            }
        }

        return $slug;
    }
}
