<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentReconciliationItemAction: string
{
    case None = 'none';
    case WebhookRequeued = 'webhook_requeued';
    case ManualReviewRequired = 'manual_review_required';
}
