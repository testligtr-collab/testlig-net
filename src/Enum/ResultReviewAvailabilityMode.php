<?php

declare(strict_types=1);

namespace App\Enum;

enum ResultReviewAvailabilityMode: string
{
    case Never = 'never';
    case AfterDeliveryClosed = 'after_delivery_closed';
    case ScheduledAfterClose = 'scheduled_after_close';
}
