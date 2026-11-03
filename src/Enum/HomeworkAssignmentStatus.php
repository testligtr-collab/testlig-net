<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Assignment lifecycle. This X01 slice writes only Assigned (no draft/closed/cancelled).
 */
enum HomeworkAssignmentStatus: string
{
    case Assigned = 'assigned';

    public function labelTr(): string
    {
        return match ($this) {
            self::Assigned => 'Atandı',
        };
    }
}
