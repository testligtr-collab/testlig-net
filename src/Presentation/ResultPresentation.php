<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Entity\Subject;
use App\Time\UtcInstant;

/**
 * Display strings for scores, percentages, and persisted UTC instants.
 * Stored decimals and timestamps are not rewritten.
 */
final class ResultPresentation
{
    public const ZONE = 'Europe/Istanbul';

    public function percent(?string $stored): ?string
    {
        $points = $this->points($stored);
        if (null === $points) {
            return null;
        }

        return '%'.$points;
    }

    public function points(?string $stored): ?string
    {
        if (null === $stored) {
            return null;
        }
        $rounded = $this->roundHalfUp(trim($stored), 2);
        if (null === $rounded) {
            return null;
        }
        if (str_contains($rounded, '.')) {
            $rounded = rtrim(rtrim($rounded, '0'), '.');
        }

        return str_replace('.', ',', $rounded);
    }

    public function instant(?\DateTimeInterface $value): ?string
    {
        if (!$value instanceof \DateTimeInterface) {
            return null;
        }

        return UtcInstant::formatForUser($value, self::ZONE, 'd.m.Y H:i');
    }

    public function subjectName(?Subject $subject): string
    {
        if (!$subject instanceof Subject) {
            return '';
        }

        return trim($subject->getName());
    }

    private function roundHalfUp(string $raw, int $scale): ?string
    {
        $negative = str_starts_with($raw, '-');
        $normalized = $this->normalize($negative ? substr($raw, 1) : $raw, $scale + 1);
        if (null === $normalized) {
            return null;
        }
        $truncated = bcadd($normalized, '0', $scale);
        $remainder = bcsub($normalized, $truncated, $scale + 1);
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);
        if (1 === bccomp($remainder, $half, $scale + 1) || 0 === bccomp($remainder, $half, $scale + 1)) {
            $truncated = bcadd($truncated, bcdiv('1', bcpow('10', (string) $scale), $scale), $scale);
        }
        if ($negative && 1 === bccomp($truncated, '0', $scale)) {
            return '-'.$truncated;
        }

        return $truncated;
    }

    /**
     * @return numeric-string|null
     */
    private function normalize(string $raw, int $scale): ?string
    {
        if ('' === $raw || !is_numeric($raw) || 1 !== preg_match('/^\d+(\.\d+)?$/', $raw)) {
            return null;
        }

        return bcadd($raw, '0', $scale);
    }
}
