<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Runtime readiness for homework PDF scanning. Enabled flags alone are not readiness.
 */
interface HomeworkPdfScanReadiness
{
    public function isReady(): bool;
}
