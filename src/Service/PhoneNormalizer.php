<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\PhoneNormalizationException;

/**
 * Canonicalizes Turkey mobile numbers to E.164 (+905XXXXXXXXX).
 *
 * Never log or rethrow the raw input; failures use a generic message.
 */
final class PhoneNormalizer
{
    public const CANONICAL_LENGTH = 13;

    public const CANONICAL_PATTERN = '/^\+905[0-9]{9}$/';

    /**
     * @return array{phone: string, normalizedPhone: string}
     */
    public function normalizePair(string $input): array
    {
        $normalized = $this->normalize($input);

        return [
            'phone' => $normalized,
            'normalizedPhone' => $normalized,
        ];
    }

    public function normalize(string $input): string
    {
        $digits = $this->extractDigits($input);
        $national = $this->toNationalMobile($digits);

        $canonical = '+90'.$national;
        if (1 !== preg_match(self::CANONICAL_PATTERN, $canonical)) {
            throw PhoneNormalizationException::invalid();
        }

        return $canonical;
    }

    public function assertCanonical(string $normalizedPhone): string
    {
        if (1 !== preg_match(self::CANONICAL_PATTERN, $normalizedPhone)) {
            throw PhoneNormalizationException::invalid();
        }

        return $normalizedPhone;
    }

    private function extractDigits(string $input): string
    {
        // Reject control bytes early — PCRE matches stop at NUL and must not canonicalize past them.
        if ('' === $input || 1 === preg_match('/[\x00-\x1F\x7F]/', $input)) {
            throw PhoneNormalizationException::invalid();
        }

        $trimmed = trim($input);
        if ('' === $trimmed) {
            throw PhoneNormalizationException::invalid();
        }

        // Keep leading + only as a marker for international form; strip other separators.
        $compact = preg_replace('/[\s\-().]/', '', $trimmed);
        if (null === $compact || '' === $compact) {
            throw PhoneNormalizationException::invalid();
        }

        if (str_starts_with($compact, '+')) {
            $compact = substr($compact, 1);
        }

        // ASCII digits only; length check avoids PCRE NUL truncation false-positives.
        if (1 !== preg_match('/^[0-9]+$/', $compact) || \strlen($compact) !== strspn($compact, '0123456789')) {
            throw PhoneNormalizationException::invalid();
        }

        return $compact;
    }

    private function toNationalMobile(string $digits): string
    {
        // 00905XXXXXXXXX
        if (str_starts_with($digits, '0090') && 14 === \strlen($digits)) {
            $digits = substr($digits, 2); // -> 905XXXXXXXXX
        }

        // 905XXXXXXXXX
        if (str_starts_with($digits, '90') && 12 === \strlen($digits)) {
            $national = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && 11 === \strlen($digits)) {
            // 05XXXXXXXXX
            $national = substr($digits, 1);
        } elseif (10 === \strlen($digits)) {
            // 5XXXXXXXXX
            $national = $digits;
        } else {
            throw PhoneNormalizationException::invalid();
        }

        // Turkey mobiles are 10-digit national numbers starting with 5 (not landline 2/3/4).
        if (1 !== preg_match('/^5[0-9]{9}$/', $national)) {
            throw PhoneNormalizationException::invalid();
        }

        return $national;
    }
}
