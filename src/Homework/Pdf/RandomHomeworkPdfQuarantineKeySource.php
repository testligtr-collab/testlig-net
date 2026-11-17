<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

final class RandomHomeworkPdfQuarantineKeySource implements HomeworkPdfQuarantineKeySource
{
    public function nextKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
