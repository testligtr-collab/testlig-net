<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Default readiness: never ready until a verified runtime acceptance replaces this binding.
 */
final class ClosedHomeworkPdfScanReadiness implements HomeworkPdfScanReadiness
{
    /**
     * @phpstan-impure
     */
    public function isReady(): bool
    {
        return false;
    }
}
