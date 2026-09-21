<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Public self-service registration account intent (Stage 2.22.3).
 *
 * Teacher / institution onboarding uses pending applications, not this enum.
 */
enum AccountType: string
{
    case Student = 'student';
    case Parent = 'parent';

    public function initialGlobalRole(): UserRole
    {
        return match ($this) {
            self::Student => UserRole::Student,
            self::Parent => UserRole::Parent,
        };
    }
}
