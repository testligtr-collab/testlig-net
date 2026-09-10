<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OptionOrderMode;
use App\Exception\AssessmentException;
use App\Repository\AssessmentItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable assessment item pinning an exact published QuestionRevision.
 */
#[ORM\Entity(repositoryClass: AssessmentItemRepository::class)]
#[ORM\Table(name: 'assessment_items')]
#[ORM\UniqueConstraint(name: 'uniq_assessment_item_section_position', columns: ['section_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_item_revision_question_revision', columns: ['assessment_revision_id', 'question_revision_id'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_item_id_section', columns: ['id', 'section_id'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_item_id_assessment_revision', columns: ['id', 'assessment_revision_id'])]
#[ORM\Index(name: 'idx_assessment_item_section', columns: ['section_id'])]
#[ORM\Index(name: 'idx_assessment_item_assessment_revision', columns: ['assessment_revision_id'])]
#[ORM\Index(name: 'idx_assessment_item_question', columns: ['question_id'])]
#[ORM\Index(name: 'idx_assessment_item_question_revision', columns: ['question_revision_id'])]
class AssessmentItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentSection $section;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentRevision $assessmentRevision;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Question $question;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'question_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private QuestionRevision $questionRevision;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $points;

    #[ORM\Column(name: 'penalty_points', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $penaltyPoints;

    #[ORM\Column]
    private bool $required;

    #[ORM\Column(name: 'option_order_mode', length: 32, nullable: true, enumType: OptionOrderMode::class)]
    private ?OptionOrderMode $optionOrderMode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        AssessmentSection $section,
        AssessmentRevision $assessmentRevision,
        Question $question,
        QuestionRevision $questionRevision,
        int $position,
        string $points,
        string $penaltyPoints,
        bool $required,
        ?OptionOrderMode $optionOrderMode,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($position < 1) {
            throw AssessmentException::invalidInput('item position must be >= 1.');
        }
        if (!$section->getRevision()->getId()->equals($assessmentRevision->getId())) {
            throw AssessmentException::conflict();
        }
        if (!$questionRevision->getQuestion()->getId()->equals($question->getId())) {
            throw AssessmentException::questionRevisionMismatch();
        }

        $this->id = $id ?? new UuidV7();
        $this->section = $section;
        $this->assessmentRevision = $assessmentRevision;
        $this->question = $question;
        $this->questionRevision = $questionRevision;
        $this->position = $position;
        $this->points = $points;
        $this->penaltyPoints = $penaltyPoints;
        $this->required = $required;
        $this->optionOrderMode = $optionOrderMode;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer AssessmentManager
     */
    public static function create(
        AssessmentSection $section,
        AssessmentRevision $assessmentRevision,
        Question $question,
        QuestionRevision $questionRevision,
        int $position,
        string $points,
        string $penaltyPoints,
        bool $required,
        ?OptionOrderMode $optionOrderMode,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $section,
            $assessmentRevision,
            $question,
            $questionRevision,
            $position,
            $points,
            $penaltyPoints,
            $required,
            $optionOrderMode,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getSection(): AssessmentSection
    {
        return $this->section;
    }

    #[Ignore]
    public function getAssessmentRevision(): AssessmentRevision
    {
        return $this->assessmentRevision;
    }

    #[Ignore]
    public function getQuestion(): Question
    {
        return $this->question;
    }

    #[Ignore]
    public function getQuestionRevision(): QuestionRevision
    {
        return $this->questionRevision;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getPoints(): string
    {
        return $this->points;
    }

    public function getPenaltyPoints(): string
    {
        return $this->penaltyPoints;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getOptionOrderMode(): ?OptionOrderMode
    {
        return $this->optionOrderMode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
