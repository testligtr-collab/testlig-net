<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One row on the student homework list. Immutable presentation DTO.
 */
final readonly class HomeworkStudentListRow
{
    public function __construct(
        public string $assignmentId,
        public string $title,
        public string $classroomName,
        public string $dueAtLocal,
        public bool $duePassed,
        public string $statusKey,
        public string $statusLabel,
        public string $actionLabel,
        public bool $outsideCurrentScope,
        public string $outsideScopeNote,
    ) {
    }
}
