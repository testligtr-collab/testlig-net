<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Escapes SQL LIKE wildcards for parameterized prefix/contains search.
 *
 * Uses '!' as ESCAPE character (MariaDB rejects multi-char ESCAPE arguments).
 */
final class AdminLikeEscape
{
    public const MAX_QUERY_LENGTH = 100;
    public const ESCAPE_CHAR = '!';

    public static function escape(string $value): string
    {
        $e = self::ESCAPE_CHAR;

        return str_replace(
            [$e, '%', '_'],
            [$e.$e, $e.'%', $e.'_'],
            $value,
        );
    }

    /**
     * Bounded, trimmed search term or null when empty/too long after trim.
     */
    public static function normalizeSearch(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }
        $trimmed = trim($raw);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed) > self::MAX_QUERY_LENGTH) {
            throw new \InvalidArgumentException('Search query exceeds maximum length.');
        }

        return $trimmed;
    }

    public static function containsPattern(string $normalized): string
    {
        return '%'.self::escape($normalized).'%';
    }

    /**
     * DQL/SQL ESCAPE clause literal (single character).
     */
    public static function escapeClause(): string
    {
        return self::ESCAPE_CHAR;
    }
}
