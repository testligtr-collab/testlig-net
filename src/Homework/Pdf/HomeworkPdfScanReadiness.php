<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Runtime readiness for homework PDF scanning. Enabled flags alone are not readiness.
 */
interface HomeworkPdfScanReadiness
{
    /**
     * May observe external/runtime state and change between calls in one request.
     *
     * @phpstan-impure
     */
    public function isReady(): bool;
}
