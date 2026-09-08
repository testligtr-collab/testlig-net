<?php

declare(strict_types=1);

namespace App\Enum;

enum SecurityAuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Cli = 'cli';
}
