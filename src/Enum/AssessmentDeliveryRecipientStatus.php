<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentDeliveryRecipientStatus: string
{
    case Eligible = 'eligible';
    case Revoked = 'revoked';
}
