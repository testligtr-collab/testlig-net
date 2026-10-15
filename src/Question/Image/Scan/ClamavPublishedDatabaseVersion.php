<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Official current.cvd.clamav.net TXT record: eight colon fields.
 * engine:main:daily:timestamp:flag:flevel:freshclam:bytecode.
 *
 * main, daily, and bytecode are compared as integers on their own fields.
 */
final class ClamavPublishedDatabaseVersion
{
    /**
     * @param array<mixed> $records
     *
     * @return array{status: string, versions: ?array{main: int, daily: int, bytecode: int}}
     */
    public static function published(array $records): array
    {
        $consensus = null;
        foreach ($records as $record) {
            if (!\is_array($record) || !isset($record['txt']) || !\is_string($record['txt'])) {
                return ['status' => 'unreadable', 'versions' => null];
            }
            if (isset($record['entries'])) {
                if (!\is_array($record['entries']) || 1 !== \count($record['entries']) || ($record['entries'][0] ?? null) !== $record['txt']) {
                    return ['status' => 'unreadable', 'versions' => null];
                }
            }
            $parsed = self::parse($record['txt']);
            if (null === $parsed) {
                return ['status' => 'unreadable', 'versions' => null];
            }
            if (null === $consensus) {
                $consensus = $parsed;
                continue;
            }
            if ($consensus !== $parsed) {
                return ['status' => 'mismatch', 'versions' => null];
            }
        }
        if (null === $consensus) {
            return ['status' => 'unavailable', 'versions' => null];
        }

        return ['status' => 'ok', 'versions' => $consensus];
    }

    /**
     * @return ?array{main: int, daily: int, bytecode: int}
     */
    public static function parse(string $txt): ?array
    {
        $txt = trim($txt);
        if (1 !== preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?::(?:0|[1-9][0-9]*)){7}$/', $txt)) {
            return null;
        }
        $fields = explode(':', $txt);
        if (8 !== \count($fields)) {
            return null;
        }
        $main = self::canonical($fields[1]);
        $daily = self::canonical($fields[2]);
        $bytecode = self::canonical($fields[7]);
        if (null === $main || null === $daily || null === $bytecode) {
            return null;
        }

        return ['main' => $main, 'daily' => $daily, 'bytecode' => $bytecode];
    }

    public static function canonical(string $value): ?int
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
