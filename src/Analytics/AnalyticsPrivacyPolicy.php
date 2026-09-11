<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * Cohort privacy threshold for aggregate analytics (k-anonymity style).
 */
final class AnalyticsPrivacyPolicy
{
    public const MIN_COHORT_SIZE = 5;

    public function shouldSuppressCohort(int $size): bool
    {
        return $size < self::MIN_COHORT_SIZE;
    }

    public function minCohortSize(): int
    {
        return self::MIN_COHORT_SIZE;
    }
}
