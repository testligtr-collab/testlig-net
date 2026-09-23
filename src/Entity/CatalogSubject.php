<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\CatalogSourceAttribution;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Repository\CatalogSubjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Student-facing course catalog subject (grade-scoped). Distinct from platform {@see Subject}.
 */
#[ORM\Entity(repositoryClass: CatalogSubjectRepository::class)]
#[ORM\Table(name: 'catalog_subjects')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_subject_grade_slug', columns: ['grade_level', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_catalog_subject_source', columns: ['source_version', 'source_code', 'source_occurrence'])]
#[ORM\Index(name: 'idx_catalog_subject_grade_status_pos', columns: ['grade_level', 'status', 'position'])]
#[ORM\Index(name: 'idx_catalog_subject_source_lookup', columns: ['source_version', 'source_code'])]
#[ORM\HasLifecycleCallbacks]
class CatalogSubject
{
    use CatalogSourceFieldsTrait;

    public const NAME_MIN = 2;
    public const NAME_MAX = 120;
    public const DESCRIPTION_MAX = 1000;
    public const SLUG_MAX = 160;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'grade_level', enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: self::SLUG_MAX)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 32, enumType: CatalogPublicationStatus::class)]
    private CatalogPublicationStatus $status;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'source_code', length: CatalogSourceAttribution::CODE_MAX, nullable: true)]
    private ?string $sourceCode = null;

    #[ORM\Column(name: 'source_version', length: CatalogSourceAttribution::VERSION_MAX, nullable: true)]
    private ?string $sourceVersion = null;

    #[ORM\Column(name: 'source_url', length: CatalogSourceAttribution::URL_MAX, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(name: 'source_occurrence', options: ['unsigned' => true])]
    private int $sourceOccurrence = 1;

    private function __construct(
        GradeLevel $gradeLevel,
        string $name,
        string $slug,
        ?string $description,
        int $position,
        \DateTimeImmutable $now,
        CatalogSourceAttribution $source,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->gradeLevel = $gradeLevel;
        $this->name = $name;
        $this->slug = $slug;
        $this->description = $description;
        $this->position = $position;
        $this->status = CatalogPublicationStatus::Draft;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->applySourceAttribution($source);
    }

    /**
     * @internal prefer CatalogWriteService
     */
    public static function createDraft(
        GradeLevel $gradeLevel,
        string $name,
        string $slug,
        ?string $description,
        int $position,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
        ?CatalogSourceAttribution $source = null,
    ): self {
        self::assertName($name);
        self::assertSlug($slug);
        self::assertPosition($position);
        $description = self::normalizeOptionalText($description, self::DESCRIPTION_MAX, 'Açıklama');

        return new self($gradeLevel, $name, $slug, $description, $position, $now, self::normalizeSourceAttribution($source), $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGradeLevel(): GradeLevel
    {
        return $this->gradeLevel;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStatus(): CatalogPublicationStatus
    {
        return $this->status;
    }

    public function getPosition(): int
    {
        return $this->position;
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
        ?string $description,
        int $position,
        \DateTimeImmutable $now,
    ): void {
        self::assertName($name);
        self::assertSlug($slug);
        self::assertPosition($position);
        $this->name = $name;
        $this->slug = $slug;
        $this->description = self::normalizeOptionalText($description, self::DESCRIPTION_MAX, 'Açıklama');
        $this->position = $position;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CatalogWriteService
     */
    public function publish(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CatalogPublicationStatus::Published)) {
            throw CatalogException::invalidTransition('Bu ders yayımlanamaz.');
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
            throw CatalogException::invalidTransition('Bu ders arşivlenemez.');
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
            throw CatalogException::invalidInput(\sprintf('Ders adı %d–%d karakter olmalıdır.', self::NAME_MIN, self::NAME_MAX));
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
