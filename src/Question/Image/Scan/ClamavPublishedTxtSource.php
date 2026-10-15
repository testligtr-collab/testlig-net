<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

interface ClamavPublishedTxtSource
{
    /**
     * @return list<array{txt: string, entries: list<string>}>
     */
    public function records(): array;
}
