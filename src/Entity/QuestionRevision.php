<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionDifficulty;
use App\Enum\QuestionSourceType;
use App\Enum\QuestionType;
use App\Exception\QuestionException;
use App\Repository\QuestionRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable question content revision. No updatedAt; ORM listener blocks mutate/delete.
 */
#[ORM\Entity(repositoryClass: QuestionRevisionRepository::class)]
#[ORM\Table(name: 'question_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_question_revision_number', columns: ['question_id', 'revision_number'])]
#[ORM\UniqueConstraint(name: 'uniq_question_revision_id_question', columns: ['id', 'question_id'])]
#[ORM\Index(name: 'idx_question_revision_question', columns: ['question_id'])]
#[ORM\Index(name: 'idx_question_revision_created_by', columns: ['created_by_id'])]
class QuestionRevision
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    #[ORM\Column(name: 'revision_number')]
    private int $revisionNumber;

    #[ORM\Column(length: 32, enumType: QuestionType::class)]
    private QuestionType $type;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'stem_content', type: Types::JSON)]
    private array $stemContent;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'explanation_content', type: Types::JSON, nullable: true)]
    private ?array $explanationContent;

    #[ORM\Column(length: 32, enumType: QuestionDifficulty::class)]
    private QuestionDifficulty $difficulty;

    #[ORM\Column(name: 'estimated_seconds', nullable: true)]
    private ?int $estimatedSeconds;

    #[ORM\Column(name: 'source_type', length: 32, enumType: QuestionSourceType::class)]
    private QuestionSourceType $sourceType;

    #[ORM\Column(name: 'source_reference', length: 255, nullable: true)]
    private ?string $sourceReference;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'content_hash', length: 64)]
    private string $contentHash;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    /**
     * @param array<string, mixed>      $stemContent
     * @param array<string, mixed>|null $explanationContent
     */
    private function __construct(
        Question $question,
        int $revisionNumber,
        QuestionType $type,
        array $stemContent,
        ?array $explanationContent,
        QuestionDifficulty $difficulty,
        ?int $estimatedSeconds,
        QuestionSourceType $sourceType,
        ?string $sourceReference,
        User $createdBy,
        string $contentHash,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($revisionNumber < 1) {
            throw QuestionException::invalidInput('revision_number must be >= 1.');
        }
        if (null !== $estimatedSeconds && $estimatedSeconds < 1) {
            throw QuestionException::invalidInput('estimated_seconds must be null or >= 1.');
        }

        $this->id = $id ?? new UuidV7();
        $this->question = $question;
        $this->revisionNumber = $revisionNumber;
        $this->type = $type;
        $this->stemContent = $stemContent;
        $this->explanationContent = $explanationContent;
        $this->difficulty = $difficulty;
        $this->estimatedSeconds = $estimatedSeconds;
        $this->sourceType = $sourceType;
        $this->sourceReference = $sourceReference;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->contentHash = $contentHash;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @param array<string, mixed>      $stemContent
     * @param array<string, mixed>|null $explanationContent
     *
     * @internal prefer QuestionManager
     */
    public static function create(
        Question $question,
        int $revisionNumber,
        QuestionType $type,
        array $stemContent,
        ?array $explanationContent,
        QuestionDifficulty $difficulty,
        ?int $estimatedSeconds,
        QuestionSourceType $sourceType,
        ?string $sourceReference,
        User $createdBy,
        string $contentHash,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $question,
            $revisionNumber,
            $type,
            $stemContent,
            $explanationContent,
            $difficulty,
            $estimatedSeconds,
            $sourceType,
            $sourceReference,
            $createdBy,
            $contentHash,
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
    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function getType(): QuestionType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStemContent(): array
    {
        return $this->stemContent;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExplanationContent(): ?array
    {
        return $this->explanationContent;
    }

    public function getDifficulty(): QuestionDifficulty
    {
        return $this->difficulty;
    }

    public function getEstimatedSeconds(): ?int
    {
        return $this->estimatedSeconds;
    }

    public function getSourceType(): QuestionSourceType
    {
        return $this->sourceType;
    }

    public function getSourceReference(): ?string
    {
        return $this->sourceReference;
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

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
