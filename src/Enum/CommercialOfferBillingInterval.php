<?php

declare(strict_types=1);

namespace App\Enum;

enum CommercialOfferBillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Relative period length used to derive subscription period ends from a UTC start.
     */
    public function toDateInterval(): \DateInterval
    {
        return match ($this) {
            self::Monthly => new \DateInterval('P1M'),
            self::Yearly => new \DateInterval('P1Y'),
        };
    }
}
