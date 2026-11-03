<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Definition lifecycle. First X01 slice writes only Draft.
 * Archived is reserved for a later slice (no new assignments from archived definitions).
 */
enum HomeworkDefinitionStatus: string
{
    case Draft = 'draft';
    case Archived = 'archived';

    public function labelTr(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Archived => 'Arşiv',
        };
    }
}
