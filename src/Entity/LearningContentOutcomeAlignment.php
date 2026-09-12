<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\LearningContentException;
use App\Repository\LearningContentOutcomeAlignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Curriculum alignment for a learning content revision (denormalized hierarchy).
 */
#[ORM\Entity(repositoryClass: LearningContentOutcomeAlignmentRepository::class)]
#[ORM\Table(name: 'learning_content_outcome_alignments')]
#[ORM\UniqueConstraint(name: 'uniq_lcoa_revision_outcome', columns: ['revision_id', 'learning_outcome_id'])]
#[ORM\UniqueConstraint(name: 'uniq_lcoa_id_revision', columns: ['id', 'revision_id'])]
#[ORM\UniqueConstraint(name: 'uniq_lcoa_id_revision_is_primary', columns: ['id', 'revision_id', 'is_primary'])]
#[ORM\Index(name: 'idx_lcoa_revision', columns: ['revision_id'])]
#[ORM\Index(name: 'idx_lcoa_outcome', columns: ['learning_outcome_id'])]
class LearningContentOutcomeAlignment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LearningContentRevision $revision;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'curriculum_program_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CurriculumProgram $curriculumProgram;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'curriculum_topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CurriculumTopic $curriculumTopic;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'learning_outcome_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CurriculumLearningOutcome $learningOutcome;

    #[ORM\Column(name: 'is_primary')]
    private bool $isPrimary;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        LearningContentRevision $revision,
        CurriculumProgram $curriculumProgram,
        Subject $subject,
        CurriculumTopic $curriculumTopic,
        CurriculumLearningOutcome $learningOutcome,
        bool $isPrimary,
        int $position,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($position < 0) {
            throw LearningContentException::alignmentInvalid('Alignment position must be >= 0.');
        }
        if (!$learningOutcome->getTopic()->getId()->equals($curriculumTopic->getId())) {
            throw LearningContentException::alignmentInvalid('Learning outcome topic mismatch.');
        }
        if (!$learningOutcome->getCurriculumProgram()->getId()->equals($curriculumProgram->getId())) {
            throw LearningContentException::alignmentInvalid('Learning outcome program mismatch.');
        }
        if (!$curriculumTopic->getUnit()->getProgram()->getId()->equals($curriculumProgram->getId())) {
            throw LearningContentException::alignmentInvalid('Topic program mismatch.');
        }
        if (!$curriculumProgram->getSubject()->getId()->equals($subject->getId())) {
            throw LearningContentException::alignmentInvalid('Program subject mismatch.');
        }

        $this->id = $id ?? new UuidV7();
        $this->revision = $revision;
        $this->curriculumProgram = $curriculumProgram;
        $this->subject = $subject;
        $this->curriculumTopic = $curriculumTopic;
        $this->learningOutcome = $learningOutcome;
        $this->isPrimary = $isPrimary;
        $this->position = $position;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer LearningContentManager
     */
    public static function create(
        LearningContentRevision $revision,
        CurriculumProgram $curriculumProgram,
        Subject $subject,
        CurriculumTopic $curriculumTopic,
        CurriculumLearningOutcome $learningOutcome,
        bool $isPrimary,
        int $position,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $revision,
            $curriculumProgram,
            $subject,
            $curriculumTopic,
            $learningOutcome,
            $isPrimary,
            $position,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getRevision(): LearningContentRevision
    {
        return $this->revision;
    }

    #[Ignore]
    public function getCurriculumProgram(): CurriculumProgram
    {
        return $this->curriculumProgram;
    }

    #[Ignore]
    public function getSubject(): Subject
    {
        return $this->subject;
    }

    #[Ignore]
    public function getCurriculumTopic(): CurriculumTopic
    {
        return $this->curriculumTopic;
    }

    #[Ignore]
    public function getLearningOutcome(): CurriculumLearningOutcome
    {
        return $this->learningOutcome;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
