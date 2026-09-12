<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\LearningContentType;
use App\Exception\LearningContentException;
use App\Repository\LearningContentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Stable learning content identity. Body lives on LearningContentRevision rows.
 */
#[ORM\Entity(repositoryClass: LearningContentRepository::class)]
#[ORM\Table(name: 'learning_contents')]
#[ORM\UniqueConstraint(name: 'uniq_lc_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_lc_id_scope', columns: ['id', 'scope'])]
#[ORM\UniqueConstraint(name: 'uniq_lc_institution_slug', columns: ['institution_id', 'slug'])]
#[ORM\Index(name: 'idx_lc_scope_status', columns: ['scope', 'status'])]
#[ORM\Index(name: 'idx_lc_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_lc_subject_grade', columns: ['subject_id', 'grade_level'])]
#[ORM\Index(name: 'idx_lc_created_by', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_lc_code', columns: ['code'])]
class LearningContent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: LearningContentScope::class)]
    private LearningContentScope $scope;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\Column(name: 'grade_level', enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\Column(name: 'content_type', length: 32, enumType: LearningContentType::class)]
    private LearningContentType $contentType;

    #[ORM\Column(length: 32, enumType: LearningContentStatus::class)]
    private LearningContentStatus $status;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 200)]
    private string $slug;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(name: 'normalized_title', length: 200)]
    private string $normalizedTitle;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary;

    #[ORM\Column(name: 'current_revision_number')]
    private int $currentRevisionNumber;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'published_revision_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?LearningContentRevision $publishedRevision;

    #[ORM\Column(name: 'published_revision_number', nullable: true)]
    private ?int $publishedRevisionNumber;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt;

    private function __construct(
        LearningContentScope $scope,
        ?Institution $institution,
        Subject $subject,
        GradeLevel $gradeLevel,
        LearningContentType $contentType,
        string $code,
        string $slug,
        string $title,
        string $normalizedTitle,
        ?string $summary,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (LearningContentScope::Platform === $scope && null !== $institution) {
            throw LearningContentException::scopeMismatch();
        }
        if (LearningContentScope::Institution === $scope && null === $institution) {
            throw LearningContentException::scopeMismatch();
        }

        $this->id = $id ?? new UuidV7();
        $this->scope = $scope;
        $this->institution = $institution;
        $this->subject = $subject;
        $this->gradeLevel = $gradeLevel;
        $this->contentType = $contentType;
        $this->status = LearningContentStatus::Draft;
        $this->code = $code;
        $this->slug = $slug;
        $this->title = $title;
        $this->normalizedTitle = $normalizedTitle;
        $this->summary = $summary;
        $this->currentRevisionNumber = 1;
        $this->publishedRevision = null;
        $this->publishedRevisionNumber = null;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->publishedAt = null;
        $this->archivedAt = null;
    }

    /**
     * @internal prefer LearningContentManager
     */
    public static function createDraft(
        LearningContentScope $scope,
        ?Institution $institution,
        Subject $subject,
        GradeLevel $gradeLevel,
        LearningContentType $contentType,
        string $code,
        string $slug,
        string $title,
        string $normalizedTitle,
        ?string $summary,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $scope,
            $institution,
            $subject,
            $gradeLevel,
            $contentType,
            $code,
            $slug,
            $title,
            $normalizedTitle,
            $summary,
            $createdBy,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getScope(): LearningContentScope
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

    public function getContentType(): LearningContentType
    {
        return $this->contentType;
    }

    public function getStatus(): LearningContentStatus
    {
        return $this->status;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getNormalizedTitle(): string
    {
        return $this->normalizedTitle;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getCurrentRevisionNumber(): int
    {
        return $this->currentRevisionNumber;
    }

    #[Ignore]
    public function getPublishedRevision(): ?LearningContentRevision
    {
        return $this->publishedRevision;
    }

    public function getPublishedRevisionNumber(): ?int
    {
        return $this->publishedRevisionNumber;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    #[Ignore]
    public function bumpRevisionNumber(\DateTimeImmutable $now): int
    {
        if (!$this->status->allowsNewRevision()) {
            throw LearningContentException::invalidTransition();
        }
        ++$this->currentRevisionNumber;
        if (LearningContentStatus::Published === $this->status) {
            $this->status = LearningContentStatus::Draft;
            $this->publishedAt = null;
        }
        $this->updatedAt = $now;

        return $this->currentRevisionNumber;
    }

    /**
     * @param array{title: string, normalizedTitle: string, slug: string} $titles
     */
    #[Ignore]
    public function updateIdentityMetadata(
        string $code,
        array $titles,
        ?string $summary,
        LearningContentType $contentType,
        GradeLevel $gradeLevel,
        \DateTimeImmutable $now,
    ): void {
        if (!\in_array($this->status, [LearningContentStatus::Draft, LearningContentStatus::InReview], true)) {
            throw LearningContentException::invalidTransition();
        }
        $this->code = $code;
        $this->title = $titles['title'];
        $this->normalizedTitle = $titles['normalizedTitle'];
        $this->slug = $titles['slug'];
        $this->summary = $summary;
        $this->contentType = $contentType;
        $this->gradeLevel = $gradeLevel;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function submitForReview(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(LearningContentStatus::InReview)) {
            throw LearningContentException::invalidTransition();
        }
        $this->status = LearningContentStatus::InReview;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function returnToDraft(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(LearningContentStatus::Draft)) {
            throw LearningContentException::invalidTransition();
        }
        $this->status = LearningContentStatus::Draft;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function publish(LearningContentRevision $revision, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(LearningContentStatus::Published)) {
            throw LearningContentException::invalidTransition();
        }
        if (!$revision->getContent()->getId()->equals($this->id)) {
            throw LearningContentException::conflict();
        }
        if ($revision->getRevisionNumber() !== $this->currentRevisionNumber) {
            throw LearningContentException::conflict();
        }
        if (!$revision->isSealed()) {
            throw LearningContentException::revisionNotSealed();
        }
        $this->status = LearningContentStatus::Published;
        $this->publishedRevision = $revision;
        $this->publishedRevisionNumber = $revision->getRevisionNumber();
        $this->publishedAt = $now;
        $this->archivedAt = null;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(LearningContentStatus::Archived)) {
            throw LearningContentException::invalidTransition();
        }
        $this->status = LearningContentStatus::Archived;
        $this->archivedAt = $now;
        $this->updatedAt = $now;
    }
}
