<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * clamd VERSION reports the loaded daily database only.
 * It does not report the loaded main or bytecode database.
 */
final class ClamdLoadedDailyVersion
{
    public const TIMEOUT_SECONDS = 5.0;
    public const MAX_RESPONSE_BYTES = 256;

    public function __construct(
        private readonly ClamdInstreamTransport $transport,
    ) {
    }

    public function read(): ?int
    {
        try {
            $reply = $this->transport->exchange(["nVERSION\n"], self::TIMEOUT_SECONDS, self::MAX_RESPONSE_BYTES);
        } catch (ClamdTransportException) {
            return null;
        }
        if (1 !== preg_match('/^ClamAV [0-9]+(?:\.[0-9]+){1,3}\/(0|[1-9][0-9]*)\/[A-Za-z]{3} [A-Za-z]{3} [ 0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4}\n$/', $reply, $matches)) {
            return null;
        }

        return ClamavPublishedDatabaseVersion::canonical($matches[1]);
    }
}
