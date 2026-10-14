<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * clamd over a local Unix socket. This class does not open TCP.
 */
final class UnixClamdTransport implements ClamdInstreamTransport
{
    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly string $socketPath,
    ) {
    }

    public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
    {
        UnixClamdSocketPath::assert($this->socketPath);
        if ($timeoutSeconds <= 0 || $maxResponseBytes < 1) {
            throw ClamdTransportException::connectionFailed();
        }
        $deadline = microtime(true) + $timeoutSeconds;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errno,
            $errstr,
            min(self::CONNECT_TIMEOUT_SECONDS, $timeoutSeconds),
        );
        if (!\is_resource($socket)) {
            throw ClamdTransportException::connectionFailed();
        }
        stream_set_blocking($socket, false);
        try {
            foreach ($framed as $piece) {
                $this->writeAll($socket, $piece, $deadline);
            }

            return $this->readReply($socket, $deadline, $maxResponseBytes);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $bytes, float $deadline): void
    {
        $offset = 0;
        $length = \strlen($bytes);
        while ($offset < $length) {
            $this->wait($socket, $deadline, true);
            $written = fwrite($socket, substr($bytes, $offset));
            if (!\is_int($written) || $written < 1) {
                throw ClamdTransportException::connectionFailed();
            }
            $offset += $written;
        }
    }

    /**
     * @param resource $socket
     */
    private function readReply($socket, float $deadline, int $maxResponseBytes): string
    {
        $buffer = '';
        while (!str_contains($buffer, "\n")) {
            if (\strlen($buffer) >= $maxResponseBytes) {
                throw ClamdTransportException::responseLimit();
            }
            $this->wait($socket, $deadline, false);
            $piece = fread($socket, 128);
            if (!\is_string($piece) || '' === $piece) {
                if (feof($socket)) {
                    throw ClamdTransportException::connectionFailed();
                }
                continue;
            }
            $buffer .= $piece;
        }
        $newline = strpos($buffer, "\n");
        if (!\is_int($newline) || $newline !== \strlen($buffer) - 1 || \strlen($buffer) > $maxResponseBytes) {
            throw ClamdTransportException::responseLimit();
        }

        return $buffer;
    }

    /**
     * @param resource $socket
     */
    private function wait($socket, float $deadline, bool $write): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw ClamdTransportException::timedOut();
        }
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1_000_000);
        $read = $write ? [] : [$socket];
        $writeSet = $write ? [$socket] : [];
        $except = null;
        $selected = stream_select($read, $writeSet, $except, $seconds, $microseconds);
        if (false === $selected) {
            throw ClamdTransportException::connectionFailed();
        }
        if (0 === $selected) {
            throw ClamdTransportException::timedOut();
        }
    }
}
