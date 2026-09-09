<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Exception\CurriculumException;
use App\Repository\CurriculumProgramRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Versioned curriculum program for a subject + grade. Published identity is immutable.
 * Composite UNIQUE (id, subject_id) supports classroom-course composite FKs.
 */
#[ORM\Entity(repositoryClass: CurriculumProgramRepository::class)]
#[ORM\Table(name: 'curriculum_programs')]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_subject_grade_code_version', columns: ['subject_id', 'grade_level', 'code', 'version'])]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_id_subject', columns: ['id', 'subject_id'])]
#[ORM\Index(name: 'idx_curriculum_subject_status', columns: ['subject_id', 'status'])]
#[ORM\Index(name: 'idx_curriculum_grade_status', columns: ['grade_level', 'status'])]
class CurriculumProgram
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\Column(name: 'grade_level', enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 180)]
    private string $normalizedName;

    #[ORM\Column(length: 64)]
    private string $version;

    #[ORM\Column(length: 32, enumType: CurriculumStatus::class)]
    private CurriculumStatus $status;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_until', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'retired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Subject $subject,
        GradeLevel $gradeLevel,
        string $code,
        string $name,
        string $normalizedName,
        string $version,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->subject = $subject;
        $this->gradeLevel = $gradeLevel;
        $this->code = $code;
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->version = $version;
        $this->status = CurriculumStatus::Draft;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CurriculumProgramManager
     */
    public static function createDraft(
        Subject $subject,
        GradeLevel $gradeLevel,
        string $code,
        string $name,
        string $normalizedName,
        string $version,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $subject,
            $gradeLevel,
            $code,
            $name,
            $normalizedName,
            $version,
            $validFrom,
            $validUntil,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getStatus(): CurriculumStatus
    {
        return $this->status;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
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
    public function renameDraft(string $name, string $normalizedName, \DateTimeImmutable $now): void
    {
        $this->assertDraftMutable();
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function changeValidityDraft(
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        \DateTimeImmutable $now,
    ): void {
        $this->assertDraftMutable();
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function publish(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CurriculumStatus::Published)) {
            throw CurriculumException::invalidTransition();
        }
        $this->status = CurriculumStatus::Published;
        $this->publishedAt = $now;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function retire(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(CurriculumStatus::Retired)) {
            throw CurriculumException::invalidTransition();
        }
        $this->status = CurriculumStatus::Retired;
        $this->retiredAt = $now;
        $this->updatedAt = $now;
    }

    private function assertDraftMutable(): void
    {
        if (!$this->status->allowsStructuralMutation()) {
            throw CurriculumException::programImmutable();
        }
    }
}
