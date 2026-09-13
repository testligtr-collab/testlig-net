<?php

declare(strict_types=1);

namespace App\Enum;

enum CommerceSubscriberType: string
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
}
