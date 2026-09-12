<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Exception\LearningContentException;
use App\Repository\StoredMediaAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Provider-neutral stored media metadata. storageKey is never serialized.
 * No real upload/SDK in Stage 2.15 — registration is metadata-only.
 */
#[ORM\Entity(repositoryClass: StoredMediaAssetRepository::class)]
#[ORM\Table(name: 'stored_media_assets')]
#[ORM\UniqueConstraint(name: 'uniq_sma_id_institution', columns: ['id', 'institution_id'])]
#[ORM\UniqueConstraint(name: 'uniq_sma_id_scope', columns: ['id', 'scope'])]
#[ORM\UniqueConstraint(name: 'uniq_sma_storage_key', columns: ['storage_key'])]
#[ORM\Index(name: 'idx_sma_scope_status', columns: ['scope', 'status'])]
#[ORM\Index(name: 'idx_sma_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_sma_kind', columns: ['kind'])]
#[ORM\Index(name: 'idx_sma_created_by', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_sma_sha256', columns: ['content_sha256'])]
class StoredMediaAsset
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: StoredMediaAssetScope::class)]
    private StoredMediaAssetScope $scope;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution;

    #[ORM\Column(length: 32, enumType: StoredMediaAssetKind::class)]
    private StoredMediaAssetKind $kind;

    #[ORM\Column(length: 32, enumType: StoredMediaAssetStatus::class)]
    private StoredMediaAssetStatus $status;

    #[ORM\Column(name: 'scan_status', length: 32, enumType: StoredMediaScanStatus::class)]
    private StoredMediaScanStatus $scanStatus;

    #[ORM\Column(name: 'storage_provider', length: 32, enumType: StoredMediaStorageProvider::class)]
    private StoredMediaStorageProvider $storageProvider;

    #[ORM\Column(name: 'storage_key', length: 512)]
    private string $storageKey;

    #[ORM\Column(name: 'original_filename', length: 255)]
    private string $originalFilename;

    #[ORM\Column(name: 'mime_type', length: 127)]
    private string $mimeType;

    #[ORM\Column(name: 'byte_size')]
    private int $byteSize;

    #[ORM\Column(name: 'content_sha256', length: 64)]
    private string $contentSha256;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'ready_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readyAt;

    #[ORM\Column(name: 'quarantined_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $quarantinedAt;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt;

    private function __construct(
        StoredMediaAssetScope $scope,
        ?Institution $institution,
        StoredMediaAssetKind $kind,
        StoredMediaStorageProvider $storageProvider,
        string $storageKey,
        string $originalFilename,
        string $mimeType,
        int $byteSize,
        string $contentSha256,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (StoredMediaAssetScope::Platform === $scope && null !== $institution) {
            throw LearningContentException::scopeMismatch();
        }
        if (StoredMediaAssetScope::Institution === $scope && null === $institution) {
            throw LearningContentException::scopeMismatch();
        }

        $this->id = $id ?? new UuidV7();
        $this->scope = $scope;
        $this->institution = $institution;
        $this->kind = $kind;
        $this->status = StoredMediaAssetStatus::Pending;
        $this->scanStatus = StoredMediaScanStatus::Pending;
        $this->storageProvider = $storageProvider;
        $this->storageKey = $storageKey;
        $this->originalFilename = $originalFilename;
        $this->mimeType = $mimeType;
        $this->byteSize = $byteSize;
        $this->contentSha256 = $contentSha256;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->readyAt = null;
        $this->quarantinedAt = null;
        $this->archivedAt = null;
    }

    /**
     * @internal prefer StoredMediaAssetManager
     */
    public static function register(
        StoredMediaAssetScope $scope,
        ?Institution $institution,
        StoredMediaAssetKind $kind,
        StoredMediaStorageProvider $storageProvider,
        string $storageKey,
        string $originalFilename,
        string $mimeType,
        int $byteSize,
        string $contentSha256,
        User $createdBy,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self(
            $scope,
            $institution,
            $kind,
            $storageProvider,
            $storageKey,
            $originalFilename,
            $mimeType,
            $byteSize,
            $contentSha256,
            $createdBy,
            $now,
            $id,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getScope(): StoredMediaAssetScope
    {
        return $this->scope;
    }

    #[Ignore]
    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    public function getKind(): StoredMediaAssetKind
    {
        return $this->kind;
    }

    public function getStatus(): StoredMediaAssetStatus
    {
        return $this->status;
    }

    public function getScanStatus(): StoredMediaScanStatus
    {
        return $this->scanStatus;
    }

    public function getStorageProvider(): StoredMediaStorageProvider
    {
        return $this->storageProvider;
    }

    #[Ignore]
    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getContentSha256(): string
    {
        return $this->contentSha256;
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

    public function getReadyAt(): ?\DateTimeImmutable
    {
        return $this->readyAt;
    }

    public function getQuarantinedAt(): ?\DateTimeImmutable
    {
        return $this->quarantinedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    #[Ignore]
    public function markScanStatus(StoredMediaScanStatus $scanStatus, \DateTimeImmutable $now): void
    {
        if (StoredMediaAssetStatus::Archived === $this->status) {
            throw LearningContentException::invalidTransition();
        }
        if ($this->scanStatus === $scanStatus) {
            return;
        }
        if (!$this->scanStatus->canTransitionTo($scanStatus)) {
            throw LearningContentException::invalidTransition();
        }
        $this->scanStatus = $scanStatus;
        if (StoredMediaScanStatus::Infected === $scanStatus) {
            if (!$this->status->canTransitionTo(StoredMediaAssetStatus::Quarantined)
                && StoredMediaAssetStatus::Quarantined !== $this->status) {
                throw LearningContentException::invalidTransition();
            }
            if (StoredMediaAssetStatus::Quarantined !== $this->status) {
                $this->status = StoredMediaAssetStatus::Quarantined;
                $this->quarantinedAt = $now;
                $this->readyAt = null;
            }
        }
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function markReady(\DateTimeImmutable $now): void
    {
        if (StoredMediaScanStatus::Clean !== $this->scanStatus) {
            throw LearningContentException::invalidTransition();
        }
        if (!$this->status->canTransitionTo(StoredMediaAssetStatus::Ready)
            && StoredMediaAssetStatus::Ready !== $this->status) {
            throw LearningContentException::invalidTransition();
        }
        $this->status = StoredMediaAssetStatus::Ready;
        $this->readyAt = $now;
        $this->quarantinedAt = null;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function quarantine(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(StoredMediaAssetStatus::Quarantined)) {
            throw LearningContentException::invalidTransition();
        }
        $this->status = StoredMediaAssetStatus::Quarantined;
        $this->quarantinedAt = $now;
        $this->readyAt = null;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(StoredMediaAssetStatus::Archived)) {
            throw LearningContentException::invalidTransition();
        }
        $this->status = StoredMediaAssetStatus::Archived;
        $this->archivedAt = $now;
        $this->updatedAt = $now;
    }
}
