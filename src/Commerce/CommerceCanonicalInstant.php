<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Time\UtcInstant;

/**
 * Canonical UTC instant formatting for commerce hashes.
 *
 * Second precision, explicit `Z` suffix — identical input instants always produce the
 * same canonical string regardless of the incoming timezone.
 */
final class CommerceCanonicalInstant
{
    public const FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function format(?\DateTimeInterface $value): ?string
    {
        if (!$value instanceof \DateTimeInterface) {
            return null;
        }

        return UtcInstant::ensure($value)->format(self::FORMAT);
    }

    /**
     * Compact period key (UTC day granularity) used for subscription period scoping.
     */
    public static function periodKey(\DateTimeInterface $value): string
    {
        return UtcInstant::ensure($value)->format('Ymd');
    }
}
