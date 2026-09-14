<?php

declare(strict_types=1);

namespace App\Enum;

enum CommercialOfferTargetType: string
{
    case Individual = 'individual';
    case Institution = 'institution';

    public function matchesPackageTarget(AccessPackageTargetType $packageTarget): bool
    {
        return match ($this) {
            self::Individual => AccessPackageTargetType::Individual === $packageTarget,
            self::Institution => AccessPackageTargetType::Institution === $packageTarget,
        };
    }

    public function toPurchaserType(): CommercePurchaserType
    {
        return match ($this) {
            self::Individual => CommercePurchaserType::User,
            self::Institution => CommercePurchaserType::Institution,
        };
    }
}
