<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Raw teacher form input for a draft homework. Strings are untrusted until the manager validates them.
 * Due date/time are Europe/Istanbul wall-clock values from the form.
 */
final readonly class HomeworkDraftInput
{
    public function __construct(
        public string $title = '',
        public string $description = '',
        public string $classroomId = '',
        public string $dueDate = '',
        public string $dueTime = '',
        public ?int $expectedRevision = null,
    ) {
    }
}
