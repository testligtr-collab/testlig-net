<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentType: string
{
    case Quiz = 'quiz';
    case PracticeTest = 'practice_test';
    case MockExam = 'mock_exam';
    case Diagnostic = 'diagnostic';
    case HomeworkBlueprint = 'homework_blueprint';
}
