<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionOrderMode;
use App\Enum\ResultReleasePolicy;
use App\Exception\AssessmentException;
use App\Repository\AssessmentRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable assessment content revision. Seal transition is the only allowed update.
 */
#[ORM\Entity(repositoryClass: AssessmentRevisionRepository::class)]
#[ORM\Table(name: 'assessment_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_assessment_revision_number', columns: ['assessment_id', 'revision_number'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_revision_id_assessment', columns: ['id', 'assessment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ar_id_assessment_number', columns: ['id', 'assessment_id', 'revision_number'])]
#[ORM\Index(name: 'idx_assessment_revision_assessment', columns: ['assessment_id'])]
#[ORM\Index(name: 'idx_assessment_revision_created_by', columns: ['created_by_id'])]
class AssessmentRevision
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Assessment $assessment;

    #[ORM\Column(name: 'revision_number')]
    private int $revisionNumber;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions;

    #[ORM\Column(name: 'duration_seconds', nullable: true)]
    private ?int $durationSeconds;

    #[ORM\Column(name: 'navigation_mode', length: 32, enumType: NavigationMode::class)]
    private NavigationMode $navigationMode;

    #[ORM\Column(name: 'question_order_mode', length: 32, enumType: QuestionOrderMode::class)]
    private QuestionOrderMode $questionOrderMode;

    #[ORM\Column(name: 'option_order_mode', length: 32, enumType: OptionOrderMode::class)]
    private OptionOrderMode $optionOrderMode;

    #[ORM\Column(name: 'result_release_policy', length: 32, enumType: ResultReleasePolicy::class)]
    private ResultReleasePolicy $resultReleasePolicy;

    #[ORM\Column(name: 'pass_score_percentage', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $passScorePercentage;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'public_content_hash', length: 64)]
    private string $publicContentHash;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    #[ORM\Column(name: 'is_sealed', options: ['default' => false])]
    private bool $isSealed;

    private function __construct(
        Assessment $assessment,
        int $revisionNumber,
        string $title,
        ?string $description,
        ?string $instructions,
        ?int $durationSeconds,
        NavigationMode $navigationMode,
        QuestionOrderMode $questionOrderMode,
        OptionOrderMode $optionOrderMode,
        ResultReleasePolicy $resultReleasePolicy,
        ?string $passScorePercentage,
        User $createdBy,
        string $publicContentHash,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($revisionNumber < 1) {
            throw AssessmentException::invalidInput('revision_number must be >= 1.');
        }
        if ($schemaVersion < 1) {
            throw AssessmentException::invalidInput('schema_version must be >= 1.');
        }

        $this->id = $id ?? new UuidV7();
        $this->assessment = $assessment;
        $this->revisionNumber = $revisionNumber;
        $this->title = $title;
        $this->description = $description;
        $this->instructions = $instructions;
        $this->durationSeconds = $durationSeconds;
        $this->navigationMode = $navigationMode;
        $this->questionOrderMode = $questionOrderMode;
        $this->optionOrderMode = $optionOrderMode;
        $this->resultReleasePolicy = $resultReleasePolicy;
        $this->passScorePercentage = $passScorePercentage;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->publicContentHash = $publicContentHash;
        $this->schemaVersion = $schemaVersion;
        $this->isSealed = false;
    }

    /**
     * @internal prefer AssessmentManager
     */
    public static function create(
        Assessment $assessment,
        int $revisionNumber,
        string $title,
        ?string $description,
        ?string $instructions,
        ?int $durationSeconds,
        NavigationMode $navigationMode,
        QuestionOrderMode $questionOrderMode,
        OptionOrderMode $optionOrderMode,
        ResultReleasePolicy $resultReleasePolicy,
        ?string $passScorePercentage,
        User $createdBy,
        string $publicContentHash,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $assessment,
            $revisionNumber,
            $title,
            $description,
            $instructions,
            $durationSeconds,
            $navigationMode,
            $questionOrderMode,
            $optionOrderMode,
            $resultReleasePolicy,
            $passScorePercentage,
            $createdBy,
            $publicContentHash,
            $schemaVersion,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getAssessment(): Assessment
    {
        return $this->assessment;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function getNavigationMode(): NavigationMode
    {
        return $this->navigationMode;
    }

    public function getQuestionOrderMode(): QuestionOrderMode
    {
        return $this->questionOrderMode;
    }

    public function getOptionOrderMode(): OptionOrderMode
    {
        return $this->optionOrderMode;
    }

    public function getResultReleasePolicy(): ResultReleasePolicy
    {
        return $this->resultReleasePolicy;
    }

    public function getPassScorePercentage(): ?string
    {
        return $this->passScorePercentage;
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

    public function getPublicContentHash(): string
    {
        return $this->publicContentHash;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function isSealed(): bool
    {
        return $this->isSealed;
    }

    #[Ignore]
    public function seal(): void
    {
        if ($this->isSealed) {
            throw AssessmentException::immutable();
        }
        $this->isSealed = true;
    }
}
