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

    /**
     * Advances a period start by one billing cycle, clamped to the last day of the
     * target month.
     *
     * Plain `add('P1M')` overflows a 31st into the following month (2026-01-31 becomes
     * 2026-03-03), which would silently skip a whole billing period, so the day of month
     * is capped instead.
     */
    public function advance(\DateTimeImmutable $periodStart): \DateTimeImmutable
    {
        $candidate = $periodStart->add($this->toDateInterval());
        $monthsToAdd = self::Monthly === $this ? 1 : 12;
        $expectedMonth = ((int) $periodStart->format('n') + $monthsToAdd - 1) % 12 + 1;
        if ((int) $candidate->format('n') === $expectedMonth) {
            return $candidate;
        }

        return $candidate->modify('last day of previous month')->setTime(
            (int) $periodStart->format('H'),
            (int) $periodStart->format('i'),
            (int) $periodStart->format('s'),
        );
    }
}
