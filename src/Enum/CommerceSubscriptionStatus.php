<?php

declare(strict_types=1);

namespace App\Enum;

enum CommerceSubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Expired => true,
            self::Pending, self::Active, self::PastDue => false,
        };
    }

    public function allowsRenewal(): bool
    {
        return match ($this) {
            self::Active, self::PastDue => true,
            self::Pending, self::Cancelled, self::Expired => false,
        };
    }
}
