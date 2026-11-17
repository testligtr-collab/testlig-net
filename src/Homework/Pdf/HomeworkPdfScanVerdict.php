<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Fail-closed homework PDF scan outcomes. Only Clean may proceed to durable staging.
 * Clean does not prove the file is harmless beyond the scanner reply for these bytes.
 */
enum HomeworkPdfScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case TimedOut = 'timed_out';
    case Unavailable = 'unavailable';
    case Error = 'error';
    case SizeLimit = 'size_limit';
    case HashMismatch = 'hash_mismatch';
}
