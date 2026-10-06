<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One resumable test on the student dashboard. Public route code only; no entity ids.
 */
final readonly class StudentContinueTestCard
{
    public function __construct(
        public string $code,
        public string $title,
        public string $subject,
        public string $stateLabel,
        public ?string $institutionName = null,
        public ?string $classroomName = null,
    ) {
    }
}
