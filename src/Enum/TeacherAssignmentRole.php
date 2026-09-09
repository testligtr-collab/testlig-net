<?php

declare(strict_types=1);

namespace App\Enum;

enum TeacherAssignmentRole: string
{
    case HomeroomTeacher = 'homeroom_teacher';
    case AssistantTeacher = 'assistant_teacher';
}
