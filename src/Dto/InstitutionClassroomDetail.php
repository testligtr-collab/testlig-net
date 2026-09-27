<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionClassroomDetail
{
    /**
     * @param list<InstitutionAssignedTeacher> $teachers
     * @param list<InstitutionPersonRow>       $students
     */
    public function __construct(
        public InstitutionClassroomRow $classroom,
        public array $teachers,
        public array $students,
    ) {
    }
}
