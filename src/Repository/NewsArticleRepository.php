<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsArticle;
use App\Enum\NewsArticleStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<NewsArticle>
 */
class NewsArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsArticle::class);
    }

    public function findOneById(Uuid $id): ?NewsArticle
    {
        $article = $this->find($id);

        return $article instanceof NewsArticle ? $article : null;
    }

    public function findOneBySlug(string $slug): ?NewsArticle
    {
        $article = $this->findOneBy(['slug' => $slug]);

        return $article instanceof NewsArticle ? $article : null;
    }

    public function findPublishedBySlug(string $slug): ?NewsArticle
    {
        $article = $this->findOneBy([
            'slug' => $slug,
            'status' => NewsArticleStatus::Published,
        ]);

        return $article instanceof NewsArticle ? $article : null;
    }

    /**
     * @return list<NewsArticle>
     */
    public function findRecent(int $limit): array
    {
        return $this->findBy([], ['updatedAt' => 'DESC'], $limit);
    }

    /**
     * @return list<NewsArticle>
     */
    public function findPublished(int $limit): array
    {
        /** @var list<NewsArticle> $articles */
        $articles = $this->createQueryBuilder('article')
            ->andWhere('article.status = :status')
            ->setParameter('status', NewsArticleStatus::Published)
            ->orderBy('article.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $articles;
    }
}
