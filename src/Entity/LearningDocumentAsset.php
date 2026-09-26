<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LearningDocumentStatus;
use App\Exception\LearningContentException;
use App\Repository\LearningDocumentAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Platform PDF kept outside the web root.
 *
 * Bytes and storageKey are immutable. There is no antivirus scanner in this
 * application, so the record stays pending until an admin marks it ready.
 */
#[ORM\Entity(repositoryClass: LearningDocumentAssetRepository::class)]
#[ORM\Table(name: 'learning_document_assets')]
#[ORM\UniqueConstraint(name: 'uniq_learning_document_storage_key', columns: ['storage_key'])]
#[ORM\Index(name: 'idx_learning_document_created_by_status', columns: ['created_by_id', 'status'])]
class LearningDocumentAsset
{
    public const MEDIA_TYPE = 'document';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'media_type', length: 32)]
    private string $mediaType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    #[ORM\Column(name: 'mime_type', length: 127)]
    private string $mimeType;

    #[ORM\Column(name: 'byte_size')]
    private int $byteSize;

    #[ORM\Column(name: 'storage_key', length: 64)]
    private string $storageKey;

    #[ORM\Column(name: 'content_sha256', length: 64)]
    private string $contentSha256;

    #[ORM\Column(length: 32, enumType: LearningDocumentStatus::class)]
    private LearningDocumentStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'ready_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readyAt;

    #[ORM\Column(name: 'quarantined_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $quarantinedAt;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt;

    private function __construct(
        User $createdBy,
        string $originalName,
        string $mimeType,
        int $byteSize,
        string $storageKey,
        string $contentSha256,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->mediaType = self::MEDIA_TYPE;
        $this->createdBy = $createdBy;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->byteSize = $byteSize;
        $this->storageKey = $storageKey;
        $this->contentSha256 = $contentSha256;
        $this->status = LearningDocumentStatus::Pending;
        $this->createdAt = $now;
        $this->readyAt = null;
        $this->quarantinedAt = null;
        $this->archivedAt = null;
    }

    public static function receive(
        User $createdBy,
        string $originalName,
        string $mimeType,
        int $byteSize,
        string $storageKey,
        string $contentSha256,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($createdBy, $originalName, $mimeType, $byteSize, $storageKey, $contentSha256, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    #[Ignore]
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    #[Ignore]
    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getContentSha256(): string
    {
        return $this->contentSha256;
    }

    public function getStatus(): LearningDocumentStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function isServable(): bool
    {
        return LearningDocumentStatus::Ready === $this->status;
    }

    #[Ignore]
    public function markReady(\DateTimeImmutable $now): void
    {
        $this->moveTo(LearningDocumentStatus::Ready);
        $this->readyAt = $now;
    }

    #[Ignore]
    public function quarantine(\DateTimeImmutable $now): void
    {
        $this->moveTo(LearningDocumentStatus::Quarantined);
        $this->quarantinedAt = $now;
        $this->readyAt = null;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        $this->moveTo(LearningDocumentStatus::Archived);
        $this->archivedAt = $now;
        $this->readyAt = null;
    }

    private function moveTo(LearningDocumentStatus $target): void
    {
        if ($this->status === $target) {
            return;
        }
        if (!$this->status->canTransitionTo($target)) {
            throw LearningContentException::invalidTransition();
        }
        $this->status = $target;
    }
}
