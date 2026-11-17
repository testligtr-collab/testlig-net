<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Stable homework PDF foundation failures. Messages are codes only (no paths, bodies, or secrets).
 */
final class HomeworkPdfScanException extends \RuntimeException
{
    public static function closed(): self
    {
        return new self(HomeworkPdfUploadGate::DENY_CODE);
    }

    public static function rejected(HomeworkPdfScanVerdict $verdict): self
    {
        return new self('homework_pdf_scan_'.$verdict->value);
    }

    public static function quarantine(): self
    {
        return new self('homework_pdf_quarantine');
    }

    public static function invalidPath(): self
    {
        return new self('homework_pdf_path');
    }

    public static function hashMismatch(): self
    {
        return self::rejected(HomeworkPdfScanVerdict::HashMismatch);
    }
}
