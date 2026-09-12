<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionLicenseSeatStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function isActive(): bool
    {
        return self::Active === $this;
    }
}
