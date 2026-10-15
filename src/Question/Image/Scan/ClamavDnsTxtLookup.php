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
        $header = unpack('nid/nflags/nquestions/nanswers', $packet);
        if (!\is_array($header) || (int) $header['id'] !== $id || 1 !== (int) $header['questions']) {
            return [];
        }
        $flags = (int) $header['flags'];
        if (0x8000 !== ($flags & 0x8000) || 0 !== ($flags & 0x7800) || 0 !== ($flags & 0x0200) || 0 !== ($flags & 0x000F)) {
            return [];
        }
        $offset = 12;
        $question = $this->readName($packet, $offset);
        if (self::HOST !== $question || $offset + 4 > \strlen($packet)) {
            return [];
        }
        $questionType = unpack('ntype/nclass', substr($packet, $offset, 4));
        $offset += 4;
        if (!\is_array($questionType) || 16 !== (int) $questionType['type'] || 1 !== (int) $questionType['class']) {
            return [];
        }
        $answerCount = (int) $header['answers'];
        if ($answerCount < 1 || $answerCount > 32) {
            return [];
        }
        /** @var array<string, string> $cnames */
        $cnames = [];
        /** @var array<string, list<string>> $texts */
        $texts = [];
        for ($answer = 0; $answer < $answerCount; ++$answer) {
            $owner = $this->readName($packet, $offset);
            if (null === $owner || $offset + 10 > \strlen($packet)) {
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
            $start = $offset;
            $offset += $length;
            $type = (int) $fields['type'];
            $class = (int) $fields['class'];
            if (5 === $type && 1 === $class) {
                $cursor = $start;
                $target = $this->readName($packet, $cursor);
                if (null === $target || $cursor !== $offset) {
                    return [];
                }
                if (isset($cnames[$owner]) && $cnames[$owner] !== $target) {
                    return [];
                }
                $cnames[$owner] = $target;
                continue;
            }
            if (16 !== $type || 1 !== $class) {
                continue;
            }
            $text = $this->txt(substr($packet, $start, $length));
            if (null === $text || '' === $text || \strlen($text) > 512) {
                return [];
            }
            $texts[$owner][] = $text;
        }

        $name = self::HOST;
        $seen = [];
        for ($hop = 0; $hop < 8; ++$hop) {
            if (isset($seen[$name])) {
                return [];
            }
            $seen[$name] = true;
            if (!isset($cnames[$name])) {
                break;
            }
            if (isset($texts[$name])) {
                return [];
            }
            $name = $cnames[$name];
            if (7 === $hop) {
                return [];
            }
        }
        if (!isset($texts[$name])) {
            return [];
        }
        $records = [];
        foreach ($texts[$name] as $text) {
            $records[] = ['txt' => $text, 'entries' => [$text]];
        }

        return $records;
    }

    private function readName(string $packet, int &$offset): ?string
    {
        $size = \strlen($packet);
        $cursor = $offset;
        $jumped = false;
        $labels = [];
        $seen = [];
        $encoded = 0;
        for ($guard = 0; $guard < 128; ++$guard) {
            if ($cursor < 0 || $cursor >= $size || isset($seen[$cursor])) {
                return null;
            }
            $seen[$cursor] = true;
            $label = \ord($packet[$cursor]);
            if (0 === $label) {
                if (!$jumped) {
                    $offset = $cursor + 1;
                }
                if ([] === $labels) {
                    return null;
                }
                $name = strtolower(implode('.', $labels));

                return \strlen($name) > 253 ? null : $name;
            }
            $kind = $label & 0xC0;
            if (0xC0 === $kind) {
                if ($cursor + 1 >= $size) {
                    return null;
                }
                $pointer = (($label & 0x3F) << 8) | \ord($packet[$cursor + 1]);
                if ($pointer >= $cursor || $pointer >= $size) {
                    return null;
                }
                if (!$jumped) {
                    $offset = $cursor + 2;
                    $jumped = true;
                }
                $cursor = $pointer;
                continue;
            }
            if (0 !== $kind || $label > 63 || $cursor + 1 + $label > $size) {
                return null;
            }
            $text = substr($packet, $cursor + 1, $label);
            if (1 !== preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $text)) {
                return null;
            }
            $labels[] = $text;
            $encoded += $label + 1;
            if ($encoded > 255) {
                return null;
            }
            $cursor += 1 + $label;
            if (!$jumped) {
                $offset = $cursor;
            }
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
