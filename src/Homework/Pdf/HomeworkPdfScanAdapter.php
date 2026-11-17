<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Scans one quarantine file whose size and SHA-256 are known.
 * Implementations must not treat MIME/PDF checks as a virus scan.
 */
interface HomeworkPdfScanAdapter
{
    public function scan(string $absolutePath, int $byteSize, string $sha256): HomeworkPdfScanVerdict;
}
