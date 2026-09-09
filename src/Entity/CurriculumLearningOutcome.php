<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CurriculumContentStatus;
use App\Exception\LearningOutcomeException;
use App\Repository\CurriculumLearningOutcomeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Ordered learning outcome under a curriculum topic.
 *
 * Denormalized unit_id + curriculum_program_id enable UNIQUE(program, code) and
 * composite FKs proving topic → unit → program hierarchy consistency.
 */
#[ORM\Entity(repositoryClass: CurriculumLearningOutcomeRepository::class)]
#[ORM\Table(name: 'curriculum_learning_outcomes')]
#[ORM\UniqueConstraint(name: 'uniq_clo_program_code', columns: ['curriculum_program_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_clo_topic_position', columns: ['topic_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_clo_id_topic', columns: ['id', 'topic_id'])]
#[ORM\UniqueConstraint(name: 'uniq_clo_id_unit', columns: ['id', 'unit_id'])]
#[ORM\UniqueConstraint(name: 'uniq_clo_id_program', columns: ['id', 'curriculum_program_id'])]
#[ORM\UniqueConstraint(name: 'uniq_clo_id_topic_program', columns: ['id', 'topic_id', 'curriculum_program_id'])]
#[ORM\UniqueConstraint(name: 'uniq_clo_id_topic_unit_program', columns: ['id', 'topic_id', 'unit_id', 'curriculum_program_id'])]
#[ORM\Index(name: 'idx_clo_topic_status', columns: ['topic_id', 'status'])]
#[ORM\Index(name: 'idx_clo_program_status', columns: ['curriculum_program_id', 'status'])]
class CurriculumLearningOutcome
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CurriculumTopic $topic;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CurriculumUnit $unit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'curriculum_program_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CurriculumProgram $curriculumProgram;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 500)]
    private string $description;

    #[ORM\Column(name: 'normalized_description', length: 500)]
    private string $normalizedDescription;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 32, enumType: CurriculumContentStatus::class)]
    private CurriculumContentStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        CurriculumTopic $topic,
        CurriculumProgram $curriculumProgram,
        string $code,
        string $description,
        string $normalizedDescription,
        int $position,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $unit = $topic->getUnit();
        $topicProgram = $unit->getProgram();
        if (!$topicProgram->getId()->equals($curriculumProgram->getId())) {
            throw LearningOutcomeException::crossHierarchy();
        }
        self::assertValidPosition($position);

        $this->id = $id ?? new UuidV7();
        $this->topic = $topic;
        $this->unit = $unit;
        $this->curriculumProgram = $curriculumProgram;
        $this->code = $code;
        $this->description = $description;
        $this->normalizedDescription = $normalizedDescription;
        $this->position = $position;
        $this->status = CurriculumContentStatus::Active;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CurriculumLearningOutcomeManager
     */
    public static function create(
        CurriculumTopic $topic,
        CurriculumProgram $curriculumProgram,
        string $code,
        string $description,
        string $normalizedDescription,
        int $position,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($topic, $curriculumProgram, $code, $description, $normalizedDescription, $position, $now, $id);
    }

    public static function assertValidPosition(int $position): void
    {
        if ($position < 1) {
            throw LearningOutcomeException::invalidInput('Learning outcome position must be greater than 0.');
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getTopic(): CurriculumTopic
    {
        return $this->topic;
    }

    #[Ignore]
    public function getUnit(): CurriculumUnit
    {
        return $this->unit;
    }

    #[Ignore]
    public function getCurriculumProgram(): CurriculumProgram
    {
        return $this->curriculumProgram;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getNormalizedDescription(): string
    {
        return $this->normalizedDescription;
    }

    public function getPosition(): int
    {
        return $this->position;
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
    public function rename(string $description, string $normalizedDescription, \DateTimeImmutable $now): void
    {
        $this->assertActive();
        $this->description = $description;
        $this->normalizedDescription = $normalizedDescription;
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
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CurriculumContentStatus::Archived)) {
            throw LearningOutcomeException::invalidTransition();
        }
        $this->status = CurriculumContentStatus::Archived;
        $this->updatedAt = $now;
    }

    private function assertActive(): void
    {
        if (CurriculumContentStatus::Archived === $this->status) {
            throw LearningOutcomeException::invalidTransition();
        }
    }
}
