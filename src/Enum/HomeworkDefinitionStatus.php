<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * First X01 slice writes only Draft. Publish/assignment states live on future HomeworkAssignment.
 */
enum HomeworkDefinitionStatus: string
{
    case Draft = 'draft';

    public function labelTr(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
        };
    }
}
