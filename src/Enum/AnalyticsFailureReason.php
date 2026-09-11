<?php

declare(strict_types=1);

namespace App\Enum;

enum AnalyticsFailureReason: string
{
    case NotFound = 'not_found';
    case Unauthorized = 'unauthorized';
    case ScopeMismatch = 'scope_mismatch';
    case ResultNotReleased = 'result_not_released';
    case ResultWithdrawn = 'result_withdrawn';
    case AnalyticsUnavailable = 'analytics_unavailable';
    case CohortSuppressed = 'cohort_suppressed';
    case InvalidInput = 'invalid_input';
    case Conflict = 'conflict';
}
