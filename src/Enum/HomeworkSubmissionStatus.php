<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Student text submission lifecycle. Evaluation is a later slice.
 */
enum HomeworkSubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function labelTr(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Submitted => 'Gönderildi',
        };
    }
}
