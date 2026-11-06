<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Teacher evaluation attributes. Subjects are HomeworkSubmission (submitted only for evaluate).
 */
final class HomeworkEvaluationPermission
{
    public const VIEW_SUBMITTED = 'HOMEWORK_EVALUATION_VIEW_SUBMITTED';
    public const EVALUATE = 'HOMEWORK_EVALUATION_EVALUATE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW_SUBMITTED, self::EVALUATE];
    }
}
