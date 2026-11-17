<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Supplies 32-char hex quarantine object keys. Client filenames are never keys.
 */
interface HomeworkPdfQuarantineKeySource
{
    public function nextKey(): string;
}
