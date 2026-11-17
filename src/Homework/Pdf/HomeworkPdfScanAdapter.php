<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Scans one locked quarantine file. Implementations must stream from the file handle,
 * not reopen the path, so the verdict applies only to those bytes.
 * MIME/PDF checks are not a virus scan.
 */
interface HomeworkPdfScanAdapter
{
    public function scan(HomeworkPdfQuarantineFile $file): HomeworkPdfScanVerdict;
}
