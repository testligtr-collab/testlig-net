<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Clean quarantine candidate for a later durable+DB commit (not performed here).
 * Ownership of the quarantine file transfers to the caller; deleteQuietly on abandon.
 */
final class HomeworkPdfCleanStaging
{
    public function __construct(
        private readonly HomeworkPdfQuarantineFile $file,
        private readonly string $displayName,
    ) {
    }

    public function file(): HomeworkPdfQuarantineFile
    {
        return $this->file;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function abandon(): void
    {
        $this->file->deleteQuietly();
    }
}
