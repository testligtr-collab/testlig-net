<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Narrow write seam for quarantine staging tests. Production uses native disk writes.
 */
interface HomeworkPdfQuarantineByteSink
{
    public function put(string $path, string $bytes): void;
}
