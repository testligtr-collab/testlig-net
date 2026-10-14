<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

interface ClamdInstreamTransport
{
    /**
     * @param iterable<string> $framed
     */
    public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string;
}
