<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\HomeworkPdfDurableStore;
use App\Homework\Pdf\HomeworkPdfQuarantineFile;
use App\Homework\Pdf\HomeworkPdfScanException;

/**
 * Test seam: after a clean scan, fail durable commit on demand.
 */
final class ControllableHomeworkPdfDurableStore extends HomeworkPdfDurableStore
{
    private bool $failNextCommit = false;

    public function failNextCommit(): void
    {
        $this->failNextCommit = true;
    }

    public function commitFromQuarantine(HomeworkPdfQuarantineFile $file): string
    {
        if ($this->failNextCommit) {
            $this->failNextCommit = false;
            throw HomeworkPdfScanException::quarantine();
        }

        return parent::commitFromQuarantine($file);
    }
}
