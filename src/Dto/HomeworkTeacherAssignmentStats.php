<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Aggregate counters for an assigned homework (no draft bodies).
 */
final readonly class HomeworkTeacherAssignmentStats
{
    public function __construct(
        public int $recipientCount,
        public int $submittedCount,
        public int $evaluatedCount,
    ) {
    }
}
