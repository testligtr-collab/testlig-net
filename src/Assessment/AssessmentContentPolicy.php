<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Exception\AssessmentException;

/**
 * Plain-text and structural policy for assessment revision content.
 */
final class AssessmentContentPolicy
{
    public const TITLE_MAX = 200;
    public const DESCRIPTION_MAX = 5000;
    public const INSTRUCTIONS_MAX = 10000;
    public const DURATION_MIN = 60;
    public const DURATION_MAX = 21600;

    public function normalizeTitle(string $title): string
    {
        $trimmed = trim($title);
        if ('' === $trimmed || mb_strlen($trimmed) > self::TITLE_MAX) {
            throw AssessmentException::invalidInput('title must be 1-200 characters.');
        }
        if ($this->containsControlChars($trimmed)) {
            throw AssessmentException::invalidInput('title contains invalid characters.');
        }

        return $trimmed;
    }

    public function normalizeOptionalPlainText(?string $value, int $maxLength, string $field): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            throw AssessmentException::invalidInput(\sprintf('%s exceeds maximum length.', $field));
        }
        if ($this->containsControlChars($trimmed)) {
            throw AssessmentException::invalidInput(\sprintf('%s contains invalid characters.', $field));
        }

        return $trimmed;
    }

    public function normalizeDurationSeconds(?int $durationSeconds): ?int
    {
        if (null === $durationSeconds) {
            return null;
        }
        if ($durationSeconds < self::DURATION_MIN || $durationSeconds > self::DURATION_MAX) {
            throw AssessmentException::invalidInput('durationSeconds must be null or between 60 and 21600.');
        }

        return $durationSeconds;
    }

    public function assertPositivePosition(int $position, string $field): void
    {
        if ($position < 1) {
            throw AssessmentException::invalidInput(\sprintf('%s must be >= 1.', $field));
        }
    }

    /**
     * When assessment duration is set, optional section durations must not exceed it in sum.
     * When assessment duration is null, section durations may each be null or within the global range.
     *
     * @param list<?int> $sectionDurations
     */
    public function assertSectionDurationsCompatible(?int $assessmentDuration, array $sectionDurations): void
    {
        $sum = 0;
        $hasSectionDuration = false;
        foreach ($sectionDurations as $duration) {
            if (null === $duration) {
                continue;
            }
            $this->normalizeDurationSeconds($duration);
            $hasSectionDuration = true;
            $sum += $duration;
        }

        if (null === $assessmentDuration) {
            return;
        }
        if ($hasSectionDuration && $sum > $assessmentDuration) {
            throw AssessmentException::invalidInput('Section durations exceed assessment duration.');
        }
    }

    private function containsControlChars(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }
}
