<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionType: string
{
    case School = 'school';
    case CourseCenter = 'course_center';
    case TutoringCenter = 'tutoring_center';
    case Other = 'other';
}
