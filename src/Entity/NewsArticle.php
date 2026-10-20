<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NewsArticleStatus;
use App\Exception\NewsArticleException;
use App\Repository\NewsArticleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: NewsArticleRepository::class)]
#[ORM\Table(name: 'news_articles')]
#[ORM\UniqueConstraint(name: 'uniq_news_article_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_news_article_status_published', columns: ['status', 'published_at'])]
#[ORM\Index(name: 'idx_news_article_category', columns: ['category_id'])]
#[ORM\Index(name: 'idx_news_article_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_news_article_submitted_by', columns: ['submitted_by_id'])]
#[ORM\Index(name: 'idx_news_article_published_by', columns: ['published_by_id'])]
class NewsArticle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?NewsCategory $category;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'submitted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $submittedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'published_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $publishedBy = null;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(length: 500)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 32, enumType: NewsArticleStatus::class)]
    private NewsArticleStatus $status;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        User $author,
        string $title,
        string $slug,
        string $summary,
        string $body,
        ?NewsCategory $category,
        \DateTimeImmutable $now,
    ) {
        $this->id = new UuidV7();
        $this->author = $author;
        $this->title = $title;
        $this->slug = $slug;
        $this->summary = $summary;
        $this->body = $body;
        $this->category = $category;
        $this->status = NewsArticleStatus::Draft;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function createDraft(
        User $author,
        string $title,
        string $slug,
        string $summary,
        string $body,
        ?NewsCategory $category,
        \DateTimeImmutable $now,
    ): self {
        return new self($author, $title, $slug, $summary, $body, $category, $now);
    }

    public function reviseDraft(string $title, string $summary, string $body, ?NewsCategory $category, \DateTimeImmutable $now): void
    {
        if (NewsArticleStatus::Draft !== $this->status) {
            throw NewsArticleException::invalidTransition();
        }
        $this->title = $title;
        $this->summary = $summary;
        $this->body = $body;
        $this->category = $category;
        $this->updatedAt = $now;
    }

    public function markSubmitted(User $actor, \DateTimeImmutable $now): void
    {
        $this->moveTo(NewsArticleStatus::InReview, $now);
        $this->submittedBy = $actor;
        $this->submittedAt = $now;
    }

    public function markReturnedToDraft(\DateTimeImmutable $now): void
    {
        $this->moveTo(NewsArticleStatus::Draft, $now);
        $this->submittedBy = null;
        $this->submittedAt = null;
        $this->publishedBy = null;
        $this->publishedAt = null;
    }

    public function markPublished(User $actor, \DateTimeImmutable $now): void
    {
        if (!$this->category instanceof NewsCategory) {
            throw NewsArticleException::categoryRequired();
        }
        $this->moveTo(NewsArticleStatus::Published, $now);
        $this->publishedBy = $actor;
        $this->publishedAt = $now;
    }

    public function markArchived(\DateTimeImmutable $now): void
    {
        $this->moveTo(NewsArticleStatus::Archived, $now);
        $this->archivedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCategory(): ?NewsCategory
    {
        return $this->category;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): NewsArticleStatus
    {
        return $this->status;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    private function moveTo(NewsArticleStatus $target, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw NewsArticleException::invalidTransition();
        }
        $this->status = $target;
        $this->updatedAt = $now;
    }
}
