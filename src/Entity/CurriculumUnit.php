<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CurriculumContentStatus;
use App\Exception\CurriculumUnitException;
use App\Repository\CurriculumUnitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Ordered unit within a curriculum program. Cannot move across programs.
 */
#[ORM\Entity(repositoryClass: CurriculumUnitRepository::class)]
#[ORM\Table(name: 'curriculum_units')]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_unit_program_code', columns: ['curriculum_program_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_unit_program_position', columns: ['curriculum_program_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_unit_id_program', columns: ['id', 'curriculum_program_id'])]
#[ORM\Index(name: 'idx_curriculum_unit_program_status', columns: ['curriculum_program_id', 'status'])]
class CurriculumUnit
{
    public const ESTIMATED_MINUTES_MAX = 10080;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'curriculum_program_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CurriculumProgram $program;

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
        CurriculumProgram $program,
        string $code,
        string $title,
        string $normalizedTitle,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        self::assertValidPosition($position);
        self::assertValidEstimatedMinutes($estimatedMinutes);

        $this->id = $id ?? new UuidV7();
        $this->program = $program;
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
     * @internal prefer CurriculumUnitManager
     */
    public static function create(
        CurriculumProgram $program,
        string $code,
        string $title,
        string $normalizedTitle,
        int $position,
        ?int $estimatedMinutes,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($program, $code, $title, $normalizedTitle, $position, $estimatedMinutes, $now, $id);
    }

    public static function assertValidPosition(int $position): void
    {
        if ($position < 1) {
            throw CurriculumUnitException::invalidInput('Curriculum unit position must be greater than 0.');
        }
    }

    public static function assertValidEstimatedMinutes(?int $estimatedMinutes): void
    {
        if (null === $estimatedMinutes) {
            return;
        }
        if ($estimatedMinutes < 1 || $estimatedMinutes > self::ESTIMATED_MINUTES_MAX) {
            throw CurriculumUnitException::invalidInput(
                'estimated_minutes must be null or between 1 and '.self::ESTIMATED_MINUTES_MAX.'.',
            );
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getProgram(): CurriculumProgram
    {
        return $this->program;
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
            throw CurriculumUnitException::invalidTransition();
        }
        $this->status = CurriculumContentStatus::Archived;
        $this->updatedAt = $now;
    }

    private function assertActive(): void
    {
        if (CurriculumContentStatus::Archived === $this->status) {
            throw CurriculumUnitException::invalidTransition();
        }
    }
}
