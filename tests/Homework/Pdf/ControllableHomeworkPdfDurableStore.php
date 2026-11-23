<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\HomeworkPdfDurableStore;
use App\Homework\Pdf\HomeworkPdfQuarantineFile;
use App\Homework\Pdf\HomeworkPdfScanException;

/**
 * Test seam: after a clean scan, fail durable commit on demand.
 * Static arm survives KernelBrowser reboot between HTTP requests.
 */
final class ControllableHomeworkPdfDurableStore extends HomeworkPdfDurableStore
{
    private static bool $failNextCommit = false;

    private bool $failThisInstance = false;

    public static function armFailNextCommit(): void
    {
        self::$failNextCommit = true;
    }

    public static function resetFailNextCommit(): void
    {
        self::$failNextCommit = false;
    }

    public function failNextCommit(): void
    {
        $this->failThisInstance = true;
        self::$failNextCommit = true;
    }

    public function commitFromQuarantine(HomeworkPdfQuarantineFile $file): string
    {
        if (self::$failNextCommit || $this->failThisInstance) {
            self::$failNextCommit = false;
            $this->failThisInstance = false;
            throw HomeworkPdfScanException::quarantine();
        }

        return parent::commitFromQuarantine($file);
    }
}
