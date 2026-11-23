<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Env-driven readiness used only when explicitly wired (tests). Production keeps Closed*.
 * Re-reads env on each call so mid-request close after scan is observable.
 */
final class FlagHomeworkPdfScanReadiness implements HomeworkPdfScanReadiness
{
    /**
     * @phpstan-impure
     */
    public function isReady(): bool
    {
        $raw = $_ENV['HOMEWORK_PDF_SCAN_READY']
            ?? $_SERVER['HOMEWORK_PDF_SCAN_READY']
            ?? getenv('HOMEWORK_PDF_SCAN_READY');
        if (false === $raw || '' === $raw) {
            return false;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOLEAN);
    }
}
