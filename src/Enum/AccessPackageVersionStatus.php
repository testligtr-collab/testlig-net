<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessPackageVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';

    public function allowsDraftMutation(): bool
    {
        return self::Draft === $this;
    }
}
