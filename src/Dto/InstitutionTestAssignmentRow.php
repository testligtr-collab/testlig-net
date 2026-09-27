<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionTestAssignmentRow
{
    public function __construct(
        public string $reference,
        public string $classroomName,
        public string $statusLabel,
        public string $opensLabel,
        public string $closesLabel,
        public int $recipientCount,
        public bool $canActivate,
        public bool $canClose,
        public string $title = '',
    ) {
    }
}
