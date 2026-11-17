<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\HomeworkPdfScanAdapter;
use App\Homework\Pdf\HomeworkPdfScanVerdict;

final class FakeHomeworkPdfScanAdapter implements HomeworkPdfScanAdapter
{
    /** @var list<array{path: string, size: int, sha: string}> */
    public array $calls = [];

    public function __construct(
        private HomeworkPdfScanVerdict $verdict = HomeworkPdfScanVerdict::Clean,
        private ?\Closure $mutatePath = null,
    ) {
    }

    public function setVerdict(HomeworkPdfScanVerdict $verdict): void
    {
        $this->verdict = $verdict;
    }

    public function scan(string $absolutePath, int $byteSize, string $sha256): HomeworkPdfScanVerdict
    {
        $this->calls[] = ['path' => $absolutePath, 'size' => $byteSize, 'sha' => $sha256];
        if (null !== $this->mutatePath) {
            ($this->mutatePath)($absolutePath);
        }

        return $this->verdict;
    }
}
