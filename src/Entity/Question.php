<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GradeLevel;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Exception\QuestionException;
use App\Repository\QuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Versioned question identity. Content lives on immutable QuestionRevision rows.
 */
#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'questions')]
#[ORM\UniqueConstraint(name: 'uniq_question_id_subject', columns: ['id', 'subject_id'])]
#[ORM\UniqueConstraint(name: 'uniq_question_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_question_id_scope', columns: ['id', 'scope'])]
#[ORM\Index(name: 'idx_question_scope_status', columns: ['scope', 'status'])]
#[ORM\Index(name: 'idx_question_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_question_subject_grade', columns: ['subject_id', 'grade_level'])]
#[ORM\Index(name: 'idx_question_created_by', columns: ['created_by_id'])]
class Question
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: QuestionScope::class)]
    private QuestionScope $scope;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\Column(name: 'grade_level', enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(length: 32, enumType: QuestionStatus::class)]
    private QuestionStatus $status;

    #[ORM\Column(name: 'current_revision_number')]
    private int $currentRevisionNumber;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        QuestionScope $scope,
        ?Institution $institution,
        Subject $subject,
        GradeLevel $gradeLevel,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (QuestionScope::Platform === $scope && null !== $institution) {
            throw QuestionException::scopeMismatch();
        }
        if (QuestionScope::Institution === $scope && null === $institution) {
            throw QuestionException::scopeMismatch();
        }

        $this->id = $id ?? new UuidV7();
        $this->scope = $scope;
        $this->institution = $institution;
        $this->subject = $subject;
        $this->gradeLevel = $gradeLevel;
        $this->createdBy = $createdBy;
        $this->status = QuestionStatus::Draft;
        $this->currentRevisionNumber = 1;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer QuestionManager
     */
    public static function createDraft(
        QuestionScope $scope,
        ?Institution $institution,
        Subject $subject,
        GradeLevel $gradeLevel,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($scope, $institution, $subject, $gradeLevel, $createdBy, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getScope(): QuestionScope
    {
        return $this->scope;
    }

    #[Ignore]
    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getSubject(): Subject
    {
        return $this->subject;
    }

    public function getGradeLevel(): GradeLevel
    {
        return $this->gradeLevel;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getStatus(): QuestionStatus
    {
        return $this->status;
    }

    public function getCurrentRevisionNumber(): int
    {
        return $this->currentRevisionNumber;
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
    public function bumpRevisionNumber(\DateTimeImmutable $now): int
    {
        if (!$this->status->allowsNewRevision()) {
            throw QuestionException::invalidTransition();
        }
        ++$this->currentRevisionNumber;
        if (QuestionStatus::Published === $this->status) {
            $this->status = QuestionStatus::Draft;
        }
        $this->updatedAt = $now;

        return $this->currentRevisionNumber;
    }

    #[Ignore]
    public function submitForReview(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(QuestionStatus::InReview)) {
            throw QuestionException::invalidTransition();
        }
        $this->status = QuestionStatus::InReview;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function returnToDraft(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(QuestionStatus::Draft)) {
            throw QuestionException::invalidTransition();
        }
        $this->status = QuestionStatus::Draft;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function publish(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(QuestionStatus::Published)) {
            throw QuestionException::invalidTransition();
        }
        $this->status = QuestionStatus::Published;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(QuestionStatus::Archived)) {
            throw QuestionException::invalidTransition();
        }
        $this->status = QuestionStatus::Archived;
        $this->updatedAt = $now;
    }
}
