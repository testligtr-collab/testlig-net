<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Assessment attempt authorization attributes for AssessmentAttemptVoter.
 */
final class AssessmentAttemptPermission
{
    public const START = 'ASSESSMENT_ATTEMPT_START';
    public const VIEW = 'ASSESSMENT_ATTEMPT_VIEW';
    public const SAVE_ANSWER = 'ASSESSMENT_ATTEMPT_SAVE_ANSWER';
    public const SUBMIT = 'ASSESSMENT_ATTEMPT_SUBMIT';
    public const CANCEL = 'ASSESSMENT_ATTEMPT_CANCEL';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::START,
            self::VIEW,
            self::SAVE_ANSWER,
            self::SUBMIT,
            self::CANCEL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function studentSelfAttributes(): array
    {
        return [
            self::START,
            self::VIEW,
            self::SAVE_ANSWER,
            self::SUBMIT,
        ];
    }
}
