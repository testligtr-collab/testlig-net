<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessLicenseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function isTerminal(): bool
    {
        return self::Expired === $this || self::Revoked === $this;
    }

    public function allowsSeatAssignment(): bool
    {
        return self::Active === $this;
    }
}
