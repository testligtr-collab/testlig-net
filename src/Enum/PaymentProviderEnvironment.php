<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentProviderEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
