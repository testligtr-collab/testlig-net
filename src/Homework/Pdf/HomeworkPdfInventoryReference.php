<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * One valid `homework_submission_attachments` row as the read-only inventory sees it. It carries no original
 * file name, no student data and no PDF content.
 */
final class HomeworkPdfInventoryReference
{
    /**
     * @param string|null $institutionId RFC 4122 institution id, or null when the owning institution cannot be
     *                                   derived consistently from the submission, assignment, recipient and
     *                                   classroom. Null is never charged to another institution.
     */
    public function __construct(
        public readonly string $storageKey,
        public readonly int $byteSize,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly HomeworkPdfAttachmentLifecycle $lifecycle,
        public readonly ?string $institutionId,
    ) {
    }
}
