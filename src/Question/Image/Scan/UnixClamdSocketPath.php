<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Local Unix socket path. TCP addresses and public hosts are rejected.
 */
final class UnixClamdSocketPath
{
    public static function assert(string $path): void
    {
        if (
            \strlen($path) < 2
            || \strlen($path) > 108
            || str_contains($path, "\0")
            || str_contains($path, '..')
            || str_contains($path, '://')
            || str_contains($path, '\\')
            || str_contains($path, ':')
            || preg_match('/\s/', $path)
            || 1 !== preg_match('#^/(?:[A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$#', $path)
        ) {
            throw ClamdTransportException::connectionFailed();
        }
    }
}
