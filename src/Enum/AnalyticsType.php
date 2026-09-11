<?php

declare(strict_types=1);

namespace App\Enum;

enum AnalyticsType: string
{
    case AssessmentSummary = 'assessment_summary';
    case Classroom = 'classroom';
    case Student = 'student';
    case Question = 'question';
    case LearningOutcome = 'learning_outcome';
}
