<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AssessmentException;
use App\Repository\AssessmentPublicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only immutable publication snapshot for a sealed assessment revision.
 */
#[ORM\Entity(repositoryClass: AssessmentPublicationRepository::class)]
#[ORM\Table(name: 'assessment_publications')]
#[ORM\UniqueConstraint(name: 'uniq_assessment_publication_number', columns: ['assessment_id', 'publication_number'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_publication_revision', columns: ['assessment_revision_id'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_publication_id_assessment', columns: ['id', 'assessment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_assessment_publication_id_revision', columns: ['id', 'assessment_revision_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ap_id_assessment_number', columns: ['id', 'assessment_id', 'publication_number'])]
#[ORM\Index(name: 'idx_assessment_publication_assessment', columns: ['assessment_id'])]
#[ORM\Index(name: 'idx_assessment_publication_published_by', columns: ['published_by_id'])]
class AssessmentPublication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Assessment $assessment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentRevision $assessmentRevision;

    #[ORM\Column(name: 'publication_number')]
    private int $publicationNumber;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $manifest;

    #[ORM\Column(name: 'manifest_hash', length: 64)]
    private string $manifestHash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'published_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $publishedBy;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    /**
     * @param array<string, mixed> $manifest
     */
    private function __construct(
        Assessment $assessment,
        AssessmentRevision $assessmentRevision,
        int $publicationNumber,
        array $manifest,
        string $manifestHash,
        User $publishedBy,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($publicationNumber < 1) {
            throw AssessmentException::invalidInput('publication_number must be >= 1.');
        }
        if ($schemaVersion < 1) {
            throw AssessmentException::invalidInput('schema_version must be >= 1.');
        }
        if (!$assessmentRevision->getAssessment()->getId()->equals($assessment->getId())) {
            throw AssessmentException::publicationInvalid('Publication revision does not belong to assessment.');
        }

        $this->id = $id ?? new UuidV7();
        $this->assessment = $assessment;
        $this->assessmentRevision = $assessmentRevision;
        $this->publicationNumber = $publicationNumber;
        $this->manifest = $manifest;
        $this->manifestHash = $manifestHash;
        $this->publishedBy = $publishedBy;
        $this->publishedAt = $now;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @internal prefer AssessmentManager
     */
    public static function create(
        Assessment $assessment,
        AssessmentRevision $assessmentRevision,
        int $publicationNumber,
        array $manifest,
        string $manifestHash,
        User $publishedBy,
        int $schemaVersion,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $assessment,
            $assessmentRevision,
            $publicationNumber,
            $manifest,
            $manifestHash,
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
    public function getAssessment(): Assessment
    {
        return $this->assessment;
    }

    #[Ignore]
    public function getAssessmentRevision(): AssessmentRevision
    {
        return $this->assessmentRevision;
    }

    public function getPublicationNumber(): int
    {
        return $this->publicationNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    public function getManifestHash(): string
    {
        return $this->manifestHash;
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
