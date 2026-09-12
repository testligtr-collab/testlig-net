<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\LearningContentException;
use App\Repository\LearningContentPublicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only immutable publication snapshot for a sealed learning content revision.
 */
#[ORM\Entity(repositoryClass: LearningContentPublicationRepository::class)]
#[ORM\Table(name: 'learning_content_publications')]
#[ORM\UniqueConstraint(name: 'uniq_lcp_content_publication_number', columns: ['content_id', 'publication_number'])]
#[ORM\UniqueConstraint(name: 'uniq_lcp_revision', columns: ['revision_id'])]
#[ORM\UniqueConstraint(name: 'uniq_lcp_id_content', columns: ['id', 'content_id'])]
#[ORM\UniqueConstraint(name: 'uniq_lcp_id_revision', columns: ['id', 'revision_id'])]
#[ORM\Index(name: 'idx_lcp_content', columns: ['content_id'])]
#[ORM\Index(name: 'idx_lcp_published_by', columns: ['published_by_id'])]
class LearningContentPublication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'content_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LearningContent $content;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private LearningContentRevision $revision;

    #[ORM\Column(name: 'publication_number')]
    private int $publicationNumber;

    #[ORM\Column(name: 'content_hash', length: 64)]
    private string $contentHash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'published_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $publishedBy;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    private function __construct(
        LearningContent $content,
        LearningContentRevision $revision,
        int $publicationNumber,
        string $contentHash,
        User $publishedBy,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($publicationNumber < 1) {
            throw LearningContentException::invalidInput('publication_number must be >= 1.');
        }
        if ($schemaVersion < 1) {
            throw LearningContentException::invalidInput('schema_version must be >= 1.');
        }
        if (!$revision->getContent()->getId()->equals($content->getId())) {
            throw LearningContentException::conflict();
        }
        if (!$revision->isSealed()) {
            throw LearningContentException::revisionNotSealed();
        }

        $this->id = $id ?? new UuidV7();
        $this->content = $content;
        $this->revision = $revision;
        $this->publicationNumber = $publicationNumber;
        $this->contentHash = $contentHash;
        $this->publishedBy = $publishedBy;
        $this->publishedAt = $now;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @internal prefer LearningContentManager
     */
    public static function create(
        LearningContent $content,
        LearningContentRevision $revision,
        int $publicationNumber,
        string $contentHash,
        User $publishedBy,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $content,
            $revision,
            $publicationNumber,
            $contentHash,
            $publishedBy,
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
    public function getContent(): LearningContent
    {
        return $this->content;
    }

    #[Ignore]
    public function getRevision(): LearningContentRevision
    {
        return $this->revision;
    }

    public function getPublicationNumber(): int
    {
        return $this->publicationNumber;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    #[Ignore]
    public function getPublishedBy(): User
    {
        return $this->publishedBy;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
