<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Readable one-time parent-link codes. Ambiguous glyphs are excluded.
 */
final class ParentLinkCodeCodec
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 12;

    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = \strlen($alphabet) - 1;
        $raw = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $raw .= $alphabet[random_int(0, $max)];
        }

        return $raw;
    }

    public function display(string $raw): string
    {
        return implode('-', str_split($raw, 4));
    }

    public function normalize(string $input): ?string
    {
        $normalized = strtoupper(str_replace([' ', '-'], '', trim($input)));
        if (self::LENGTH !== \strlen($normalized)) {
            return null;
        }
        if (1 !== preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }
}
