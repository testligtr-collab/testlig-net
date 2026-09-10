<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Exception\AssessmentException;

/**
 * Decimal score validation/normalization via bcmath (no PHP float).
 */
final class AssessmentScore
{
    private const SCALE = 2;

    /**
     * @return numeric-string
     */
    public static function normalizePoints(string $raw): string
    {
        $normalized = self::normalizeDecimal($raw);
        if (1 !== bccomp($normalized, '0', self::SCALE)) {
            throw AssessmentException::invalidPoints('points must be greater than 0.');
        }

        return $normalized;
    }

    /**
     * @return numeric-string
     */
    public static function normalizePenalty(string $raw, string $points): string
    {
        $normalized = self::normalizeDecimal($raw);
        $normalizedPoints = self::normalizeDecimal($points);
        if (-1 === bccomp($normalized, '0', self::SCALE)) {
            throw AssessmentException::invalidPoints('penaltyPoints must be >= 0.');
        }
        if (1 === bccomp($normalized, $normalizedPoints, self::SCALE)) {
            throw AssessmentException::invalidPoints('penaltyPoints must not exceed points.');
        }

        return $normalized;
    }

    /**
     * @return numeric-string|null
     */
    public static function normalizePassScorePercentage(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }
        $normalized = self::normalizeDecimal($raw);
        if (-1 === bccomp($normalized, '0', self::SCALE) || 1 === bccomp($normalized, '100', self::SCALE)) {
            throw AssessmentException::invalidInput('passScorePercentage must be between 0 and 100.');
        }

        return $normalized;
    }

    /**
     * @return numeric-string
     */
    private static function normalizeDecimal(string $raw): string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed || !is_numeric($trimmed) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw AssessmentException::invalidPoints('Decimal value must be a numeric string.');
        }
        if (!\extension_loaded('bcmath')) {
            throw AssessmentException::invalidPoints('bcmath extension is required.');
        }

        return bcadd($trimmed, '0', self::SCALE);
    }
}
