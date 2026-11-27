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

    private static int $readAttempts = 0;

    private bool $failThisInstance = false;

    public static function armFailNextCommit(): void
    {
        self::$failNextCommit = true;
    }

    public static function resetFailNextCommit(): void
    {
        self::$failNextCommit = false;
    }

    public static function resetReadAttempts(): void
    {
        self::$readAttempts = 0;
    }

    public static function readAttempts(): int
    {
        return self::$readAttempts;
    }

    public function failNextCommit(): void
    {
        $this->failThisInstance = true;
        self::$failNextCommit = true;
    }

    public function assertReadable(string $storageKey, string $sha256, int $byteSize): string
    {
        ++self::$readAttempts;

        return parent::assertReadable($storageKey, $sha256, $byteSize);
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
