<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\QuestionException;
use App\Repository\QuestionRevisionAlignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable curriculum alignment for a question revision.
 * Hierarchy consistency enforced via composite FKs + constructor guards.
 */
#[ORM\Entity(repositoryClass: QuestionRevisionAlignmentRepository::class)]
#[ORM\Table(name: 'question_revision_alignments')]
#[ORM\UniqueConstraint(name: 'uniq_qra_revision_outcome', columns: ['revision_id', 'learning_outcome_id'])]
#[ORM\UniqueConstraint(name: 'uniq_qra_id_revision', columns: ['id', 'revision_id'])]
#[ORM\Index(name: 'idx_qra_revision', columns: ['revision_id'])]
#[ORM\Index(name: 'idx_qra_outcome', columns: ['learning_outcome_id'])]
class QuestionRevisionAlignment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionRevision $revision;

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

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        QuestionRevision $revision,
        CurriculumProgram $curriculumProgram,
        Subject $subject,
        CurriculumTopic $curriculumTopic,
        CurriculumLearningOutcome $learningOutcome,
        bool $isPrimary,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (!$learningOutcome->getTopic()->getId()->equals($curriculumTopic->getId())) {
            throw QuestionException::alignmentInvalid('Learning outcome topic mismatch.');
        }
        if (!$learningOutcome->getCurriculumProgram()->getId()->equals($curriculumProgram->getId())) {
            throw QuestionException::alignmentInvalid('Learning outcome program mismatch.');
        }
        if (!$curriculumTopic->getUnit()->getProgram()->getId()->equals($curriculumProgram->getId())) {
            throw QuestionException::alignmentInvalid('Topic program mismatch.');
        }
        if (!$curriculumProgram->getSubject()->getId()->equals($subject->getId())) {
            throw QuestionException::alignmentInvalid('Program subject mismatch.');
        }

        $this->id = $id ?? new UuidV7();
        $this->revision = $revision;
        $this->curriculumProgram = $curriculumProgram;
        $this->subject = $subject;
        $this->curriculumTopic = $curriculumTopic;
        $this->learningOutcome = $learningOutcome;
        $this->isPrimary = $isPrimary;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer QuestionManager
     */
    public static function create(
        QuestionRevision $revision,
        CurriculumProgram $curriculumProgram,
        Subject $subject,
        CurriculumTopic $curriculumTopic,
        CurriculumLearningOutcome $learningOutcome,
        bool $isPrimary,
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
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getRevision(): QuestionRevision
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
