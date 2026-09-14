<?php

declare(strict_types=1);

namespace App\Enum;

enum CommercialOfferStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';

    public function allowsDraftMutation(): bool
    {
        return self::Draft === $this;
    }

    public function allowsActivation(): bool
    {
        return self::Draft === $this;
    }

    public function allowsRetirement(): bool
    {
        return self::Draft === $this || self::Active === $this;
    }

    public function allowsNewOrder(): bool
    {
        return self::Active === $this;
    }
}
