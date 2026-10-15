<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Scanner outcomes. Only Clean may become a ready asset.
 */
enum QuestionImageScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Error = 'error';
    case SizeLimit = 'size_limit';
    case Malformed = 'malformed';
    case ConnectionFailed = 'connection_failed';
    case TimedOut = 'timed_out';
    case HashMismatch = 'hash_mismatch';
    case CleanupFailed = 'cleanup_failed';
    case StaleSignature = 'stale_signature';
    case Unchanged = 'unchanged';
}
