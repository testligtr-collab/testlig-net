<?php

declare(strict_types=1);

namespace App\Enum;

enum CommercialOfferBillingType: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';

    public function requiresBillingInterval(): bool
    {
        return self::Recurring === $this;
    }
}
