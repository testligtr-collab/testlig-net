<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Symfony\Component\Uid\Uuid;

/**
 * Safe short UI reference derived from UUID (not a secret).
 */
final class AdminShortRef
{
    public static function fromUuid(Uuid $id): string
    {
        return substr($id->toRfc4122(), 0, 8);
    }
}
