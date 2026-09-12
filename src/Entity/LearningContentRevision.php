<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LearningContentSourceType;
use App\Exception\LearningContentException;
use App\Repository\LearningContentRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Learning content revision. Seal transition is the only allowed update after create.
 */
#[ORM\Entity(repositoryClass: LearningContentRevisionRepository::class)]
#[ORM\Table(name: 'learning_content_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_lcr_content_revision_number', columns: ['content_id', 'revision_number'])]
#[ORM\UniqueConstraint(name: 'uniq_lcr_id_content', columns: ['id', 'content_id'])]
#[ORM\UniqueConstraint(name: 'uniq_lcr_id_content_number', columns: ['id', 'content_id', 'revision_number'])]
#[ORM\Index(name: 'idx_lcr_content', columns: ['content_id'])]
#[ORM\Index(name: 'idx_lcr_created_by', columns: ['created_by_id'])]
class LearningContentRevision
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'content_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LearningContent $content;

    #[ORM\Column(name: 'revision_number')]
    private int $revisionNumber;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'structured_content', type: Types::JSON)]
    private array $structuredContent;

    #[ORM\Column(name: 'estimated_minutes', nullable: true)]
    private ?int $estimatedMinutes;

    #[ORM\Column(length: 16)]
    private string $language;

    #[ORM\Column(name: 'source_type', length: 32, enumType: LearningContentSourceType::class)]
    private LearningContentSourceType $sourceType;

    #[ORM\Column(name: 'source_reference', length: 255, nullable: true)]
    private ?string $sourceReference;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'accessibility_metadata', type: Types::JSON, nullable: true)]
    private ?array $accessibilityMetadata;

    #[ORM\Column(name: 'content_hash', length: 64)]
    private string $contentHash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'sealed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sealedAt;

    #[ORM\Column(name: 'is_sealed', options: ['default' => false])]
    private bool $isSealed;

    /**
     * @param array<string, mixed>      $structuredContent
     * @param array<string, mixed>|null $accessibilityMetadata
     */
    private function __construct(
        LearningContent $content,
        int $revisionNumber,
        int $schemaVersion,
        array $structuredContent,
        ?int $estimatedMinutes,
        string $language,
        LearningContentSourceType $sourceType,
        ?string $sourceReference,
        ?array $accessibilityMetadata,
        string $contentHash,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($revisionNumber < 1) {
            throw LearningContentException::invalidInput('revision_number must be >= 1.');
        }
        if ($schemaVersion < 1) {
            throw LearningContentException::invalidInput('schema_version must be >= 1.');
        }
        if (null !== $estimatedMinutes && $estimatedMinutes < 1) {
            throw LearningContentException::invalidInput('estimated_minutes must be null or >= 1.');
        }
        if (1 !== preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) {
            throw LearningContentException::invalidInput('language must be a BCP-47-like code (e.g. tr, en-US).');
        }

        $this->id = $id ?? new UuidV7();
        $this->content = $content;
        $this->revisionNumber = $revisionNumber;
        $this->schemaVersion = $schemaVersion;
        $this->structuredContent = $structuredContent;
        $this->estimatedMinutes = $estimatedMinutes;
        $this->language = $language;
        $this->sourceType = $sourceType;
        $this->sourceReference = $sourceReference;
        $this->accessibilityMetadata = $accessibilityMetadata;
        $this->contentHash = $contentHash;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->sealedAt = null;
        $this->isSealed = false;
    }

    /**
     * @param array<string, mixed>      $structuredContent
     * @param array<string, mixed>|null $accessibilityMetadata
     *
     * @internal prefer LearningContentManager
     */
    public static function create(
        LearningContent $content,
        int $revisionNumber,
        int $schemaVersion,
        array $structuredContent,
        ?int $estimatedMinutes,
        string $language,
        LearningContentSourceType $sourceType,
        ?string $sourceReference,
        ?array $accessibilityMetadata,
        string $contentHash,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $content,
            $revisionNumber,
            $schemaVersion,
            $structuredContent,
            $estimatedMinutes,
            $language,
            $sourceType,
            $sourceReference,
            $accessibilityMetadata,
            $contentHash,
            $createdBy,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getContent(): LearningContent
    {
        return $this->content;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStructuredContent(): array
    {
        return $this->structuredContent;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getSourceType(): LearningContentSourceType
    {
        return $this->sourceType;
    }

    public function getSourceReference(): ?string
    {
        return $this->sourceReference;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAccessibilityMetadata(): ?array
    {
        return $this->accessibilityMetadata;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
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

    public function getSealedAt(): ?\DateTimeImmutable
    {
        return $this->sealedAt;
    }

    public function isSealed(): bool
    {
        return $this->isSealed;
    }

    /**
     * @param array<string, mixed>      $structuredContent
     * @param array<string, mixed>|null $accessibilityMetadata
     *
     * @internal prefer LearningContentManager — only while unsealed
     */
    #[Ignore]
    public function replaceUnsealedContent(
        array $structuredContent,
        ?int $estimatedMinutes,
        string $language,
        LearningContentSourceType $sourceType,
        ?string $sourceReference,
        ?array $accessibilityMetadata,
        string $contentHash,
        int $schemaVersion,
    ): void {
        if ($this->isSealed) {
            throw LearningContentException::revisionSealed();
        }
        if (null !== $estimatedMinutes && $estimatedMinutes < 1) {
            throw LearningContentException::invalidInput('estimated_minutes must be null or >= 1.');
        }
        if (1 !== preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) {
            throw LearningContentException::invalidInput('language must be a BCP-47-like code (e.g. tr, en-US).');
        }
        if ($schemaVersion < 1) {
            throw LearningContentException::invalidInput('schema_version must be >= 1.');
        }

        $this->structuredContent = $structuredContent;
        $this->estimatedMinutes = $estimatedMinutes;
        $this->language = $language;
        $this->sourceType = $sourceType;
        $this->sourceReference = $sourceReference;
        $this->accessibilityMetadata = $accessibilityMetadata;
        $this->contentHash = $contentHash;
        $this->schemaVersion = $schemaVersion;
    }

    #[Ignore]
    public function seal(\DateTimeImmutable $now): void
    {
        if ($this->isSealed) {
            throw LearningContentException::immutable();
        }
        $this->isSealed = true;
        $this->sealedAt = $now;
    }
}
