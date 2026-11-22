<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Env-driven readiness used only when explicitly wired (tests). Production keeps Closed*.
 */
final class FlagHomeworkPdfScanReadiness implements HomeworkPdfScanReadiness
{
    public function __construct(
        private readonly bool $ready,
    ) {
    }

    public function isReady(): bool
    {
        return $this->ready;
    }
}
