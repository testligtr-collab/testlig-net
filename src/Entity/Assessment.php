<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Exception\AssessmentException;
use App\Repository\AssessmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Stable assessment identity. Content lives on immutable AssessmentRevision rows.
 */
#[ORM\Entity(repositoryClass: AssessmentRepository::class)]
#[ORM\Table(name: 'assessments')]
#[ORM\UniqueConstraint(name: 'uniq_assessment_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_id_scope', columns: ['id', 'scope'])]
#[ORM\Index(name: 'idx_assessment_scope_status', columns: ['scope', 'status'])]
#[ORM\Index(name: 'idx_assessment_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_assessment_grade', columns: ['grade_level'])]
#[ORM\Index(name: 'idx_assessment_created_by', columns: ['created_by_id'])]
class Assessment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: AssessmentScope::class)]
    private AssessmentScope $scope;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution;

    #[ORM\Column(length: 32, enumType: AssessmentType::class)]
    private AssessmentType $type;

    #[ORM\Column(name: 'grade_level', enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(length: 32, enumType: AssessmentStatus::class)]
    private AssessmentStatus $status;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'current_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?AssessmentRevision $currentRevision;

    #[ORM\Column(name: 'current_revision_number', nullable: true)]
    private ?int $currentRevisionNumber;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'published_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?AssessmentRevision $publishedRevision;

    #[ORM\Column(name: 'published_revision_number', nullable: true)]
    private ?int $publishedRevisionNumber;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        AssessmentScope $scope,
        ?Institution $institution,
        AssessmentType $type,
        GradeLevel $gradeLevel,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (AssessmentScope::Platform === $scope && null !== $institution) {
            throw AssessmentException::scopeMismatch();
        }
        if (AssessmentScope::Institution === $scope && null === $institution) {
            throw AssessmentException::scopeMismatch();
        }

        $this->id = $id ?? new UuidV7();
        $this->scope = $scope;
        $this->institution = $institution;
        $this->type = $type;
        $this->gradeLevel = $gradeLevel;
        $this->createdBy = $createdBy;
        $this->status = AssessmentStatus::Draft;
        $this->currentRevision = null;
        $this->currentRevisionNumber = null;
        $this->publishedRevision = null;
        $this->publishedRevisionNumber = null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AssessmentManager
     */
    public static function createDraft(
        AssessmentScope $scope,
        ?Institution $institution,
        AssessmentType $type,
        GradeLevel $gradeLevel,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($scope, $institution, $type, $gradeLevel, $createdBy, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getScope(): AssessmentScope
    {
        return $this->scope;
    }

    #[Ignore]
    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    public function getType(): AssessmentType
    {
        return $this->type;
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

    public function getStatus(): AssessmentStatus
    {
        return $this->status;
    }

    #[Ignore]
    public function getCurrentRevision(): ?AssessmentRevision
    {
        return $this->currentRevision;
    }

    public function getCurrentRevisionNumber(): ?int
    {
        return $this->currentRevisionNumber;
    }

    #[Ignore]
    public function getPublishedRevision(): ?AssessmentRevision
    {
        return $this->publishedRevision;
    }

    public function getPublishedRevisionNumber(): ?int
    {
        return $this->publishedRevisionNumber;
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
     * Prepare identity for a new revision: Published → Draft only. Does not touch current pointers.
     */
    #[Ignore]
    public function prepareForNewRevision(\DateTimeImmutable $now): void
    {
        if (!$this->status->allowsNewRevision()) {
            throw AssessmentException::invalidTransition();
        }
        if (AssessmentStatus::Published === $this->status) {
            $this->status = AssessmentStatus::Draft;
        }
        $this->updatedAt = $now;
    }

    /**
     * Point current revision id+number at a sealed or about-to-seal revision of this assessment.
     */
    #[Ignore]
    public function assignCurrentRevision(AssessmentRevision $revision, \DateTimeImmutable $now): void
    {
        if (!$revision->getAssessment()->getId()->equals($this->id)) {
            throw AssessmentException::conflict();
        }
        $this->currentRevision = $revision;
        $this->currentRevisionNumber = $revision->getRevisionNumber();
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function submitForReview(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AssessmentStatus::InReview)) {
            throw AssessmentException::invalidTransition();
        }
        $this->status = AssessmentStatus::InReview;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function returnToDraft(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AssessmentStatus::Draft)) {
            throw AssessmentException::invalidTransition();
        }
        $this->status = AssessmentStatus::Draft;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function publish(AssessmentRevision $revision, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AssessmentStatus::Published)) {
            throw AssessmentException::invalidTransition();
        }
        if (!$revision->getAssessment()->getId()->equals($this->id)) {
            throw AssessmentException::conflict();
        }
        if (null === $this->currentRevision || !$this->currentRevision->getId()->equals($revision->getId())) {
            throw AssessmentException::conflict();
        }
        if ($this->currentRevisionNumber !== $revision->getRevisionNumber()) {
            throw AssessmentException::conflict();
        }
        $this->status = AssessmentStatus::Published;
        $this->publishedRevision = $revision;
        $this->publishedRevisionNumber = $revision->getRevisionNumber();
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AssessmentStatus::Archived)) {
            throw AssessmentException::invalidTransition();
        }
        $this->status = AssessmentStatus::Archived;
        $this->updatedAt = $now;
    }
}
