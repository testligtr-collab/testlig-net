<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentDeliveryAudienceType: string
{
    case Institution = 'institution';
    case Classroom = 'classroom';
    case Student = 'student';
}
