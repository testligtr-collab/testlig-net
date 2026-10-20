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
use App\Service\FreshUserLoader;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class NewsArticleManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NewsArticleRepository $articles,
        private readonly NewsCategoryRepository $categories,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly FreshUserLoader $freshUsers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createCategory(User $actor, string $name): NewsCategory
    {
        $actorId = $actor->getId();
        $name = NewsText::plain($name, 2, 80, false);

        return $this->transactional(function () use ($actorId, $name): NewsCategory {
            $freshActor = $this->freshActor($actorId);
            $category = NewsCategory::create($name, $this->uniqueCategorySlug(NewsText::slug($name, 'kategori')), $freshActor, $this->clock->now());
            $this->entityManager->persist($category);
            $this->entityManager->flush();

            return $category;
        });
    }

    public function createDraft(User $actor, string $title, string $summary, string $body, ?Uuid $categoryId): NewsArticle
    {
        $actorId = $actor->getId();
        [$title, $summary, $body] = $this->fields($title, $summary, $body);

        return $this->transactional(function () use ($actorId, $title, $summary, $body, $categoryId): NewsArticle {
            $freshActor = $this->freshActor($actorId);
            $article = NewsArticle::createDraft(
                $freshActor,
                $title,
                $this->uniqueArticleSlug(NewsText::slug($title, 'haber')),
                $summary,
                $body,
                $this->optionalCategory($categoryId),
                $this->clock->now(),
            );
            $this->entityManager->persist($article);
            $this->entityManager->flush();

            return $article;
        });
    }

    public function updateDraft(User $actor, NewsArticle $article, string $title, string $summary, string $body, ?Uuid $categoryId): void
    {
        $actorId = $actor->getId();
        $articleId = $article->getId();
        [$title, $summary, $body] = $this->fields($title, $summary, $body);
        $this->transactional(function () use ($actorId, $articleId, $title, $summary, $body, $categoryId): void {
            $locked = $this->lockArticle($articleId);
            $freshActor = $this->freshActor($actorId);
            $locked->reviseDraft($freshActor, $title, $summary, $body, $this->optionalCategory($categoryId), $this->clock->now());
            $this->entityManager->flush();
        });
    }

    public function submitForReview(User $actor, NewsArticle $article): void
    {
        $this->transition($actor, $article, static function (NewsArticle $locked, User $freshActor, \DateTimeImmutable $now): void {
            $locked->markSubmitted($freshActor, $now);
        });
    }

    public function returnToDraft(User $actor, NewsArticle $article): void
    {
        $this->transition($actor, $article, static function (NewsArticle $locked, User $freshActor, \DateTimeImmutable $now): void {
            $locked->markReturnedToDraft($now);
        });
    }

    public function publish(User $actor, NewsArticle $article): void
    {
        $this->transition($actor, $article, static function (NewsArticle $locked, User $freshActor, \DateTimeImmutable $now): void {
            if ($locked->blocksPublishBy($freshActor)) {
                throw NewsArticleException::separationOfDuties();
            }
            $locked->markPublished($freshActor, $now);
        });
    }

    public function archive(User $actor, NewsArticle $article): void
    {
        $this->transition($actor, $article, static function (NewsArticle $locked, User $freshActor, \DateTimeImmutable $now): void {
            $locked->markArchived($now);
        });
    }

    /**
     * @param \Closure(NewsArticle, User, \DateTimeImmutable): void $mutate
     */
    private function transition(User $actor, NewsArticle $article, \Closure $mutate): void
    {
        $actorId = $actor->getId();
        $articleId = $article->getId();
        $this->transactional(function () use ($actorId, $articleId, $mutate): void {
            $locked = $this->lockArticle($articleId);
            $freshActor = $this->freshActor($actorId);
            $mutate($locked, $freshActor, $this->clock->now());
            $this->entityManager->flush();
        });
    }

    private function lockArticle(Uuid $articleId): NewsArticle
    {
        $article = $this->entityManager->createQueryBuilder()
            ->select('article')
            ->from(NewsArticle::class, 'article')
            ->andWhere('article.id = :id')
            ->setParameter('id', $articleId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$article instanceof NewsArticle) {
            throw NewsArticleException::notFound();
        }

        return $article;
    }

    private function freshActor(Uuid $actorId): User
    {
        $actor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$actor instanceof User || !$this->adminAuthorization->canManageNews($actor)) {
            throw NewsArticleException::unauthorized();
        }

        return $actor;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        try {
            return $this->entityManager->wrapInTransaction($work);
        } catch (NewsArticleException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw NewsArticleException::conflict();
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
