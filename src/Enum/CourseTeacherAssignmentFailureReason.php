<?php

declare(strict_types=1);

namespace App\Enum;

enum CourseTeacherAssignmentFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case CrossInstitution = 'cross_institution';
    case InstitutionNotOperable = 'institution_not_operable';
    case YearNotOperable = 'year_not_operable';
    case ClassroomNotOperable = 'classroom_not_operable';
    case CourseNotOperable = 'course_not_operable';
    case CurriculumNotPublished = 'curriculum_not_published';
}
