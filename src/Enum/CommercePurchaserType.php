<?php

declare(strict_types=1);

namespace App\Enum;

enum CommercePurchaserType: string
{
    case User = 'user';
    case Institution = 'institution';

    public function toLicenseeType(): AccessLicenseLicenseeType
    {
        return match ($this) {
            self::User => AccessLicenseLicenseeType::User,
            self::Institution => AccessLicenseLicenseeType::Institution,
        };
    }

    public function toSubscriberType(): CommerceSubscriberType
    {
        return match ($this) {
            self::User => CommerceSubscriberType::User,
            self::Institution => CommerceSubscriberType::Institution,
        };
    }
}
