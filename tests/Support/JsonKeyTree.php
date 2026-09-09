<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recursively collects object keys from json_decode trees.
 */
final class JsonKeyTree
{
    /**
     * @return list<string>
     */
    public static function collectKeys(mixed $data): array
    {
        $keys = [];
        self::walk($data, $keys);

        return array_values(array_unique($keys));
    }

    /**
     * @param list<string> $keys
     */
    private static function walk(mixed $data, array &$keys): void
    {
        if (!\is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $keys[] = $key;
            }
            self::walk($value, $keys);
        }
    }
}
