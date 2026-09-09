<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionOrderMode;
use App\Exception\AssessmentException;
use App\Repository\AssessmentSectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable section within an assessment revision.
 */
#[ORM\Entity(repositoryClass: AssessmentSectionRepository::class)]
#[ORM\Table(name: 'assessment_sections')]
#[ORM\UniqueConstraint(name: 'uniq_assessment_section_revision_position', columns: ['revision_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_section_id_revision', columns: ['id', 'revision_id'])]
#[ORM\Index(name: 'idx_assessment_section_revision', columns: ['revision_id'])]
class AssessmentSection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentRevision $revision;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'duration_seconds', nullable: true)]
    private ?int $durationSeconds;

    #[ORM\Column(name: 'question_order_mode', length: 32, enumType: QuestionOrderMode::class)]
    private QuestionOrderMode $questionOrderMode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        AssessmentRevision $revision,
        string $title,
        ?string $instructions,
        int $position,
        ?int $durationSeconds,
        QuestionOrderMode $questionOrderMode,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($position < 1) {
            throw AssessmentException::invalidInput('section position must be >= 1.');
        }

        $this->id = $id ?? new UuidV7();
        $this->revision = $revision;
        $this->title = $title;
        $this->instructions = $instructions;
        $this->position = $position;
        $this->durationSeconds = $durationSeconds;
        $this->questionOrderMode = $questionOrderMode;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer AssessmentManager
     */
    public static function create(
        AssessmentRevision $revision,
        string $title,
        ?string $instructions,
        int $position,
        ?int $durationSeconds,
        QuestionOrderMode $questionOrderMode,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($revision, $title, $instructions, $position, $durationSeconds, $questionOrderMode, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getRevision(): AssessmentRevision
    {
        return $this->revision;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function getQuestionOrderMode(): QuestionOrderMode
    {
        return $this->questionOrderMode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
