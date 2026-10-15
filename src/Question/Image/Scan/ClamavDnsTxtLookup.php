<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * One TXT lookup for current.cvd.clamav.net with a short UDP timeout and a byte cap.
 */
final class ClamavDnsTxtLookup implements ClamavPublishedTxtSource
{
    public const MAX_BYTES = 2048;
    public const TIMEOUT_SECONDS = 2.0;
    public const HOST = 'current.cvd.clamav.net';

    public function __construct(
        private readonly string $nameserver,
        private readonly int $port = 53,
        private readonly float $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {
    }

    public static function fromNameserver(ResolvConfNameserver $nameserver): self
    {
        return new self($nameserver->first());
    }

    /**
     * @return list<array{txt: string, entries: list<string>}>
     */
    public function records(): array
    {
        if (1 !== preg_match('/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/', $this->nameserver) || $this->port < 1 || $this->port > 65535) {
            return [];
        }
        $id = random_int(0, 65535);
        $query = $this->query($id);
        $packet = $this->exchange($query);
        if (!\is_string($packet)) {
            return [];
        }

        return $this->txtRecords($packet, $id);
    }

    private function query(int $id): string
    {
        $name = '';
        foreach (explode('.', self::HOST) as $label) {
            $name .= \chr(\strlen($label)).$label;
        }
        $name .= "\0";

        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$name.pack('nn', 16, 1);
    }

    private function exchange(string $query): ?string
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'udp://'.$this->nameserver.':'.$this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
        );
        if (!\is_resource($socket)) {
            return null;
        }
        stream_set_timeout($socket, (int) ceil($this->timeoutSeconds));
        try {
            if (\strlen($query) !== fwrite($socket, $query)) {
                return null;
            }
            $packet = fread($socket, self::MAX_BYTES);
            $meta = stream_get_meta_data($socket);
        } finally {
            fclose($socket);
        }
        if (!\is_string($packet) || '' === $packet || \strlen($packet) > self::MAX_BYTES) {
            return null;
        }
        if ($meta['timed_out']) {
            return null;
        }

        return $packet;
    }

    /**
     * @return list<array{txt: string, entries: list<string>}>
     */
    private function txtRecords(string $packet, int $id): array
    {
        if (\strlen($packet) < 12) {
            return [];
        }
        $header = unpack('nid/nflags/nqd/nan/nns/nar', $packet);
        if (!\is_array($header) || (int) $header['id'] !== $id || 1 !== (int) $header['qd'] || (int) $header['an'] < 1) {
            return [];
        }
        $offset = 12;
        if (null === $this->skipName($packet, $offset) || $offset + 4 > \strlen($packet)) {
            return [];
        }
        $offset += 4;
        $records = [];
        $answers = (int) $header['an'];
        for ($answer = 0; $answer < $answers; ++$answer) {
            if (null === $this->skipName($packet, $offset) || $offset + 10 > \strlen($packet)) {
                return [];
            }
            $fields = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            if (!\is_array($fields)) {
                return [];
            }
            $length = (int) $fields['length'];
            if ($offset + $length > \strlen($packet)) {
                return [];
            }
            $data = substr($packet, $offset, $length);
            $offset += $length;
            if (16 !== (int) $fields['type'] || 1 !== (int) $fields['class']) {
                continue;
            }
            $text = $this->txt($data);
            if (null === $text || \strlen($text) > 512) {
                return [];
            }
            $records[] = ['txt' => $text, 'entries' => [$text]];
        }

        return $records;
    }

    private function skipName(string $packet, int &$offset): ?bool
    {
        $guard = 0;
        $length = \strlen($packet);
        while ($offset < $length && $guard < 64) {
            ++$guard;
            $label = \ord($packet[$offset]);
            if (0 === $label) {
                ++$offset;

                return true;
            }
            if (($label & 0xC0) === 0xC0) {
                $offset += 2;

                return $offset <= $length;
            }
            $offset += 1 + $label;
        }

        return null;
    }

    private function txt(string $data): ?string
    {
        $text = '';
        $offset = 0;
        $length = \strlen($data);
        while ($offset < $length) {
            $part = \ord($data[$offset]);
            ++$offset;
            if ($offset + $part > $length) {
                return null;
            }
            $text .= substr($data, $offset, $part);
            $offset += $part;
        }

        return $text;
    }
}
