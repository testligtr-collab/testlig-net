<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessPackageStatus: string
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
        return self::Active === $this;
    }

    public function allowsNewLicense(): bool
    {
        return self::Active === $this;
    }
}
