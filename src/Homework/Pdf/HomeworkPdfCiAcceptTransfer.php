<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Near-limit CI transfer reporting helpers. Not a production upload surface.
 */
final class HomeworkPdfCiAcceptTransfer
{
    /**
     * Transfer is complete only when the expected bytes were fully streamed.
     * Clean staging success proves that (adapter requires streamMatched for clean).
     * Unavailable, timeout, connection failure, or other rejects without that proof → false.
     */
    public static function nearLimitTransferComplete(bool $cleanStagingSucceeded): bool
    {
        return $cleanStagingSucceeded;
    }
}
