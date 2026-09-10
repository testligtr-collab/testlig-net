<?php

declare(strict_types=1);

namespace App\Time;

/**
 * Canonical UTC helpers for persistence and presentation.
 *
 * Database DATETIME values are UTC wall-clock. User::$timezone is presentation-only.
 */
final class UtcInstant
{
    public const ZONE = 'UTC';

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZONE);
    }

    /**
     * Normalize any instant to a UTC DateTimeImmutable (same unix timestamp).
     */
    public static function ensure(\DateTimeInterface $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($value)->setTimezone(self::zone());
    }

    /**
     * Convert a UTC (or any) instant to the user's presentation timezone without mutating persistence.
     */
    public static function forPresentation(\DateTimeInterface $value, string $userTimezone): \DateTimeImmutable
    {
        return self::ensure($value)->setTimezone(new \DateTimeZone($userTimezone));
    }

    /**
     * Format a persisted UTC instant using the user's presentation timezone.
     */
    public static function formatForUser(
        \DateTimeInterface $value,
        string $userTimezone,
        string $format = \DateTimeInterface::ATOM,
    ): string {
        return self::forPresentation($value, $userTimezone)->format($format);
    }
}
