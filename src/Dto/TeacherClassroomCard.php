<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class TeacherClassroomCard
{
    /**
     * @param list<InstitutionTestAssignmentRow> $assignments
     * @param list<InstitutionClassroomOption>   $tests
     */
    public function __construct(
        public string $reference,
        public string $label,
        public string $institutionName,
        public array $assignments,
        public array $tests,
    ) {
    }
}
