<?php

declare(strict_types=1);

namespace App\Enum;

enum AnalyticsSuppressionReason: string
{
    case CohortBelowThreshold = 'cohort_below_threshold';
    case ZeroDenominator = 'zero_denominator';
    case NoReleasedResults = 'no_released_results';
    case DeliveryNotClassroomScoped = 'delivery_not_classroom_scoped';
}
