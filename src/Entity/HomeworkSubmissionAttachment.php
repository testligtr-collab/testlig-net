<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HomeworkSubmissionAttachmentRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Optional single PDF on a homework submission draft. Private storage key only; no public URL.
 *
 * @internal prefer HomeworkPdfAttachmentManager
 */
#[ORM\Entity(repositoryClass: HomeworkSubmissionAttachmentRepository::class)]
#[ORM\Table(name: 'homework_submission_attachments')]
#[ORM\UniqueConstraint(name: 'uniq_hw_sub_attach_submission', columns: ['submission_id'])]
#[ORM\UniqueConstraint(name: 'uniq_hw_sub_attach_storage', columns: ['storage_key'])]
class HomeworkSubmissionAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'submission_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private HomeworkSubmission $submission;

    #[ORM\Column(name: 'storage_key', length: 32)]
    private string $storageKey;

    #[ORM\Column(name: 'display_name', length: 132)]
    private string $displayName;

    #[ORM\Column(name: 'byte_size', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $byteSize;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        HomeworkSubmission $submission,
        string $storageKey,
        string $displayName,
        int $byteSize,
        string $sha256,
        \DateTimeImmutable $now,
    ) {
        $this->id = new UuidV7();
        $this->submission = $submission;
        $this->storageKey = $storageKey;
        $this->displayName = $displayName;
        $this->byteSize = $byteSize;
        $this->sha256 = $sha256;
        $this->createdAt = UtcInstant::ensure($now);
    }

    /**
     * @internal prefer HomeworkPdfAttachmentManager
     */
    public static function register(
        HomeworkSubmission $submission,
        string $storageKey,
        string $displayName,
        int $byteSize,
        string $sha256,
        \DateTimeImmutable $now,
    ): self {
        return new self($submission, $storageKey, $displayName, $byteSize, $sha256, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubmission(): HomeworkSubmission
    {
        return $this->submission;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
