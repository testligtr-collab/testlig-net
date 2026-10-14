<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Stable clamd failure codes. The message is the code and nothing from the socket or reply.
 */
final class ClamdTransportException extends \RuntimeException
{
    private function __construct(string $code)
    {
        parent::__construct($code);
    }

    public static function connectionFailed(): self
    {
        return new self('clamd_connection');
    }

    public static function timedOut(): self
    {
        return new self('clamd_timeout');
    }

    public static function responseLimit(): self
    {
        return new self('clamd_response_limit');
    }
}
