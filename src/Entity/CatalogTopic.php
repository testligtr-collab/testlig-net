<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CatalogPublicationStatus;
use App\Exception\CatalogException;
use App\Repository\CatalogTopicRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: CatalogTopicRepository::class)]
#[ORM\Table(name: 'catalog_topics')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_topic_unit_slug', columns: ['unit_id', 'slug'])]
#[ORM\Index(name: 'idx_catalog_topic_unit_status_pos', columns: ['unit_id', 'status', 'position'])]
#[ORM\HasLifecycleCallbacks]
class CatalogTopic
{
    public const NAME_MIN = 2;
    public const NAME_MAX = 180;
    public const SUMMARY_MAX = 3000;
    public const SLUG_MAX = 180;
    public const ESTIMATED_MINUTES_MIN = 1;
    public const ESTIMATED_MINUTES_MAX = 600;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CatalogUnit $unit;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: self::SLUG_MAX)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 32, enumType: CatalogPublicationStatus::class)]
    private CatalogPublicationStatus $status;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'estimated_minutes', nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        CatalogUnit $unit,
        string $name,
        string $slug,
        ?string $summary,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->unit = $unit;
        $this->name = $name;
        $this->slug = $slug;
        $this->summary = $summary;
        $this->position = $position;
        $this->estimatedMinutes = $estimatedMinutes;
        $this->status = CatalogPublicationStatus::Draft;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CatalogWriteService
     */
    public static function createDraft(
        CatalogUnit $unit,
        string $name,
        string $slug,
        ?string $summary,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        self::assertName($name);
        self::assertSlug($slug);
        self::assertPosition($position);
        self::assertEstimatedMinutes($estimatedMinutes);
        $summary = self::normalizeOptionalText($summary, self::SUMMARY_MAX, 'Özet');

        return new self($unit, $name, $slug, $summary, $position, $estimatedMinutes, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getUnit(): CatalogUnit
    {
        return $this->unit;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getStatus(): CatalogPublicationStatus
    {
        return $this->status;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
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

    /**
     * @internal prefer CatalogWriteService
     */
    public function updateDetails(
        string $name,
        string $slug,
        ?string $summary,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
    ): void {
        self::assertName($name);
        self::assertSlug($slug);
        self::assertPosition($position);
        self::assertEstimatedMinutes($estimatedMinutes);
        $this->name = $name;
        $this->slug = $slug;
        $this->summary = self::normalizeOptionalText($summary, self::SUMMARY_MAX, 'Özet');
        $this->position = $position;
        $this->estimatedMinutes = $estimatedMinutes;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CatalogWriteService
     */
    public function publish(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CatalogPublicationStatus::Published)) {
            throw CatalogException::invalidTransition('Bu konu yayımlanamaz.');
        }
        $this->status = CatalogPublicationStatus::Published;
        if (null === $this->publishedAt) {
            $this->publishedAt = $now;
        }
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CatalogWriteService
     */
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CatalogPublicationStatus::Archived)) {
            throw CatalogException::invalidTransition('Bu konu arşivlenemez.');
        }
        $this->status = CatalogPublicationStatus::Archived;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function isDraft(): bool
    {
        return CatalogPublicationStatus::Draft === $this->status;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function assertName(string $name): void
    {
        $len = mb_strlen($name, 'UTF-8');
        if ($len < self::NAME_MIN || $len > self::NAME_MAX) {
            throw CatalogException::invalidInput(\sprintf('Konu adı %d–%d karakter olmalıdır.', self::NAME_MIN, self::NAME_MAX));
        }
    }

    private static function assertSlug(string $slug): void
    {
        if ('' === $slug || \strlen($slug) > self::SLUG_MAX || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw CatalogException::invalidInput('Slug geçersiz.');
        }
    }

    private static function assertPosition(int $position): void
    {
        if ($position < 0) {
            throw CatalogException::invalidInput('Sıra sıfır veya pozitif olmalıdır.');
        }
    }

    private static function assertEstimatedMinutes(?int $minutes): void
    {
        if (null === $minutes) {
            return;
        }
        if ($minutes < self::ESTIMATED_MINUTES_MIN || $minutes > self::ESTIMATED_MINUTES_MAX) {
            throw CatalogException::invalidInput(\sprintf(
                'Tahmini süre %d–%d dakika olmalıdır.',
                self::ESTIMATED_MINUTES_MIN,
                self::ESTIMATED_MINUTES_MAX,
            ));
        }
    }

    private static function normalizeOptionalText(?string $value, int $max, string $label): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed, 'UTF-8') > $max) {
            throw CatalogException::invalidInput(\sprintf('%s en fazla %d karakter olabilir.', $label, $max));
        }

        return $trimmed;
    }
}
