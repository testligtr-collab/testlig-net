<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CurriculumContentStatus;
use App\Exception\CurriculumTopicException;
use App\Repository\CurriculumTopicRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Topic tree under a curriculum unit. Max depth 2 (root + child).
 * Root position uniqueness is enforced in the manager (MariaDB NULL UNIQUE quirk).
 */
#[ORM\Entity(repositoryClass: CurriculumTopicRepository::class)]
#[ORM\Table(name: 'curriculum_topics')]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_topic_unit_code', columns: ['unit_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_topic_unit_parent_position', columns: ['unit_id', 'parent_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_topic_id_unit', columns: ['id', 'unit_id'])]
#[ORM\Index(name: 'idx_curriculum_topic_unit_status', columns: ['unit_id', 'status'])]
#[ORM\Index(name: 'idx_curriculum_topic_parent', columns: ['parent_id'])]
class CurriculumTopic
{
    public const ESTIMATED_MINUTES_MAX = 10080;
    public const MAX_DEPTH = 2;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CurriculumUnit $unit;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $parent;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(name: 'normalized_title', length: 180)]
    private string $normalizedTitle;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'estimated_minutes', nullable: true)]
    private ?int $estimatedMinutes;

    #[ORM\Column(length: 32, enumType: CurriculumContentStatus::class)]
    private CurriculumContentStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        CurriculumUnit $unit,
        ?self $parent,
        string $code,
        string $title,
        string $normalizedTitle,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (null !== $parent) {
            if (!$parent->getUnit()->getId()->equals($unit->getId())) {
                throw CurriculumTopicException::crossUnit();
            }
            if (null !== $parent->getParent()) {
                throw CurriculumTopicException::depthExceeded();
            }
            if (CurriculumContentStatus::Archived === $parent->getStatus()) {
                throw CurriculumTopicException::invalidInput('Cannot create child under an archived parent topic.');
            }
        }
        self::assertValidPosition($position);
        self::assertValidEstimatedMinutes($estimatedMinutes);

        $this->id = $id ?? new UuidV7();
        $this->unit = $unit;
        $this->parent = $parent;
        $this->code = $code;
        $this->title = $title;
        $this->normalizedTitle = $normalizedTitle;
        $this->position = $position;
        $this->estimatedMinutes = $estimatedMinutes;
        $this->status = CurriculumContentStatus::Active;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CurriculumTopicManager
     */
    public static function createRoot(
        CurriculumUnit $unit,
        string $code,
        string $title,
        string $normalizedTitle,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($unit, null, $code, $title, $normalizedTitle, $position, $estimatedMinutes, $now, $id);
    }

    /**
     * @internal prefer CurriculumTopicManager
     */
    public static function createChild(
        CurriculumUnit $unit,
        self $parent,
        string $code,
        string $title,
        string $normalizedTitle,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($unit, $parent, $code, $title, $normalizedTitle, $position, $estimatedMinutes, $now, $id);
    }

    public static function assertValidPosition(int $position): void
    {
        if ($position < 1) {
            throw CurriculumTopicException::invalidInput('Curriculum topic position must be greater than 0.');
        }
    }

    public static function assertValidEstimatedMinutes(?int $estimatedMinutes): void
    {
        if (null === $estimatedMinutes) {
            return;
        }
        if ($estimatedMinutes < 1 || $estimatedMinutes > self::ESTIMATED_MINUTES_MAX) {
            throw CurriculumTopicException::invalidInput(
                'estimated_minutes must be null or between 1 and '.self::ESTIMATED_MINUTES_MAX.'.',
            );
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getUnit(): CurriculumUnit
    {
        return $this->unit;
    }

    #[Ignore]
    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getNormalizedTitle(): string
    {
        return $this->normalizedTitle;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function getStatus(): CurriculumContentStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDepth(): int
    {
        return null === $this->parent ? 1 : 2;
    }

    #[Ignore]
    public function rename(string $title, string $normalizedTitle, \DateTimeImmutable $now): void
    {
        $this->assertActive();
        $this->title = $title;
        $this->normalizedTitle = $normalizedTitle;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function reorder(int $position, \DateTimeImmutable $now): void
    {
        $this->assertActive();
        self::assertValidPosition($position);
        $this->position = $position;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function changeEstimatedMinutes(?int $estimatedMinutes, \DateTimeImmutable $now): void
    {
        $this->assertActive();
        self::assertValidEstimatedMinutes($estimatedMinutes);
        $this->estimatedMinutes = $estimatedMinutes;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CurriculumContentStatus::Archived)) {
            throw CurriculumTopicException::invalidTransition();
        }
        $this->status = CurriculumContentStatus::Archived;
        $this->updatedAt = $now;
    }

    private function assertActive(): void
    {
        if (CurriculumContentStatus::Archived === $this->status) {
            throw CurriculumTopicException::invalidTransition();
        }
    }
}
