<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

final class NativeHomeworkPdfQuarantineByteSink implements HomeworkPdfQuarantineByteSink
{
    public function put(string $path, string $bytes): void
    {
        if (false === file_put_contents($path, $bytes, \LOCK_EX)) {
            throw HomeworkPdfScanException::quarantine();
        }
    }
}
