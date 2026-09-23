<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CatalogPublicationStatus;
use App\Exception\CatalogException;
use App\Repository\CatalogTopicLessonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Ordered placement of a LearningContent under a CatalogTopic (navigation only).
 *
 * Content lifecycle stays on LearningContent / Revision / Publication (Stage 2.15).
 */
#[ORM\Entity(repositoryClass: CatalogTopicLessonRepository::class)]
#[ORM\Table(name: 'catalog_topic_lessons')]
#[ORM\UniqueConstraint(name: 'uniq_ctl_topic_slug', columns: ['catalog_topic_id', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_ctl_topic_position', columns: ['catalog_topic_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_ctl_topic_content', columns: ['catalog_topic_id', 'learning_content_id'])]
#[ORM\Index(name: 'idx_ctl_topic_visibility_pos', columns: ['catalog_topic_id', 'visibility_status', 'position'])]
#[ORM\Index(name: 'idx_ctl_learning_content', columns: ['learning_content_id'])]
#[ORM\Index(name: 'idx_ctl_created_by', columns: ['created_by_id'])]
#[ORM\HasLifecycleCallbacks]
class CatalogTopicLesson
{
    public const TITLE_MIN = 2;
    public const TITLE_MAX = 200;
    public const SUMMARY_MAX = 3000;
    public const SLUG_MAX = 180;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'catalog_topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CatalogTopic $catalogTopic;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'learning_content_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private LearningContent $learningContent;

    #[ORM\Column(length: self::SLUG_MAX)]
    private string $slug;

    #[ORM\Column(name: 'display_title', length: self::TITLE_MAX)]
    private string $displayTitle;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'visibility_status', length: 32, enumType: CatalogPublicationStatus::class)]
    private CatalogPublicationStatus $visibilityStatus;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    private function __construct(
        CatalogTopic $catalogTopic,
        LearningContent $learningContent,
        string $slug,
        string $displayTitle,
        ?string $summary,
        int $position,
        ?User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->catalogTopic = $catalogTopic;
        $this->learningContent = $learningContent;
        $this->slug = $slug;
        $this->displayTitle = $displayTitle;
        $this->summary = $summary;
        $this->position = $position;
        $this->visibilityStatus = CatalogPublicationStatus::Draft;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CatalogTopicLessonManager
     */
    public static function createDraft(
        CatalogTopic $catalogTopic,
        LearningContent $learningContent,
        string $slug,
        string $displayTitle,
        ?string $summary,
        int $position,
        ?User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        self::assertSlug($slug);
        self::assertTitle($displayTitle);
        self::assertPosition($position);
        $summary = self::normalizeSummary($summary);

        return new self(
            $catalogTopic,
            $learningContent,
            $slug,
            $displayTitle,
            $summary,
            $position,
            $createdBy,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCatalogTopic(): CatalogTopic
    {
        return $this->catalogTopic;
    }

    public function getLearningContent(): LearningContent
    {
        return $this->learningContent;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDisplayTitle(): string
    {
        return $this->displayTitle;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getVisibilityStatus(): CatalogPublicationStatus
    {
        return $this->visibilityStatus;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /**
     * @internal prefer CatalogTopicLessonManager
     */
    public function updateDetails(
        string $slug,
        string $displayTitle,
        ?string $summary,
        int $position,
        \DateTimeImmutable $now,
    ): void {
        if (CatalogPublicationStatus::Draft !== $this->visibilityStatus) {
            throw CatalogException::invalidTransition('Yalnız taslak yerleşimler düzenlenebilir.');
        }
        self::assertSlug($slug);
        self::assertTitle($displayTitle);
        self::assertPosition($position);
        $this->slug = $slug;
        $this->displayTitle = $displayTitle;
        $this->summary = self::normalizeSummary($summary);
        $this->position = $position;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CatalogTopicLessonManager
     */
    public function publish(\DateTimeImmutable $now): void
    {
        if (!$this->visibilityStatus->canTransitionTo(CatalogPublicationStatus::Published)) {
            throw CatalogException::invalidTransition('Bu yerleşim yayımlanamaz.');
        }
        $this->visibilityStatus = CatalogPublicationStatus::Published;
        if (null === $this->publishedAt) {
            $this->publishedAt = $now;
        }
        $this->updatedAt = $now;
    }

    /**
     * Irreversible. Physical delete is not supported.
     *
     * @internal prefer CatalogTopicLessonManager
     */
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->visibilityStatus->canTransitionTo(CatalogPublicationStatus::Archived)) {
            throw CatalogException::invalidTransition('Bu yerleşim arşivlenemez.');
        }
        $this->visibilityStatus = CatalogPublicationStatus::Archived;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function isDraft(): bool
    {
        return CatalogPublicationStatus::Draft === $this->visibilityStatus;
    }

    #[Ignore]
    public function isPublished(): bool
    {
        return CatalogPublicationStatus::Published === $this->visibilityStatus;
    }

    #[Ignore]
    public function isArchived(): bool
    {
        return CatalogPublicationStatus::Archived === $this->visibilityStatus;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function assertTitle(string $title): void
    {
        $len = mb_strlen($title, 'UTF-8');
        if ($len < self::TITLE_MIN || $len > self::TITLE_MAX) {
            throw CatalogException::invalidInput(\sprintf(
                'Yerleşim başlığı %d–%d karakter olmalıdır.',
                self::TITLE_MIN,
                self::TITLE_MAX,
            ));
        }
    }

    private static function assertSlug(string $slug): void
    {
        if ('' === $slug || \strlen($slug) > self::SLUG_MAX || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw CatalogException::invalidInput('Yerleşim slug geçersiz.');
        }
    }

    private static function assertPosition(int $position): void
    {
        if ($position < 0) {
            throw CatalogException::invalidInput('Sıra sıfır veya pozitif olmalıdır.');
        }
    }

    private static function normalizeSummary(?string $summary): ?string
    {
        if (null === $summary) {
            return null;
        }
        $trimmed = trim($summary);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed, 'UTF-8') > self::SUMMARY_MAX) {
            throw CatalogException::invalidInput(\sprintf('Özet en fazla %d karakter olabilir.', self::SUMMARY_MAX));
        }

        return $trimmed;
    }
}
