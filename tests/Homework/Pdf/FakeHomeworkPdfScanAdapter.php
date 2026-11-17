<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\HomeworkPdfQuarantineFile;
use App\Homework\Pdf\HomeworkPdfScanAdapter;
use App\Homework\Pdf\HomeworkPdfScanVerdict;

final class FakeHomeworkPdfScanAdapter implements HomeworkPdfScanAdapter
{
    /** @var list<array{path: string, size: int, sha: string}> */
    public array $calls = [];

    public function __construct(
        private HomeworkPdfScanVerdict $verdict = HomeworkPdfScanVerdict::Clean,
        private ?\Closure $afterScan = null,
    ) {
    }

    public function setVerdict(HomeworkPdfScanVerdict $verdict): void
    {
        $this->verdict = $verdict;
    }

    public function scan(HomeworkPdfQuarantineFile $file): HomeworkPdfScanVerdict
    {
        $this->calls[] = [
            'path' => $file->absolutePath(),
            'size' => $file->byteSize(),
            'sha' => $file->sha256(),
        ];
        if (null !== $this->afterScan) {
            ($this->afterScan)($file);
        }

        return $this->verdict;
    }
}
