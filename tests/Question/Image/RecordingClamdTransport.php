<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Question\Image\Scan\ClamdInstreamTransport;

final class RecordingClamdTransport implements ClamdInstreamTransport
{
    public string $sent = '';
    public float $timeoutSeconds = 0;
    public int $maxResponseBytes = 0;

    public function __construct(
        private readonly string $response,
    ) {
    }

    public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
    {
        $this->timeoutSeconds = $timeoutSeconds;
        $this->maxResponseBytes = $maxResponseBytes;
        foreach ($framed as $piece) {
            $this->sent .= $piece;
        }

        return $this->response;
    }
}
