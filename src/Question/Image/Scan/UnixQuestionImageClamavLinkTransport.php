<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Sends only nPING and nVERSION. It does not send INSTREAM or read an asset.
 */
final class UnixQuestionImageClamavLinkTransport implements QuestionImageClamavLinkTransport
{
    private const TIMEOUT_SECONDS = 5.0;

    private readonly UnixClamdTransport $sockets;

    public function __construct(string $socketPath)
    {
        $this->sockets = new UnixClamdTransport($socketPath);
    }

    public function ping(): string
    {
        return $this->sockets->exchange(["nPING\n"], self::TIMEOUT_SECONDS, 32);
    }

    public function version(): string
    {
        return $this->sockets->exchange(["nVERSION\n"], self::TIMEOUT_SECONDS, 256);
    }
}
