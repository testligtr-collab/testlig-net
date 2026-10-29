<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Question\Image\Scan\ClamdInstreamTransport;
use App\Question\Image\Scan\ClamdTransportException;

final class ConnectionFailedClamdTransport implements ClamdInstreamTransport
{
    public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
    {
        throw ClamdTransportException::connectionFailed();
    }
}
