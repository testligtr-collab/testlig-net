<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Question bank authorization attributes for QuestionVoter.
 */
final class QuestionPermission
{
    public const VIEW = 'QUESTION_VIEW';
    public const MANAGE = 'QUESTION_MANAGE';
    public const REVIEW = 'QUESTION_REVIEW';
    public const PUBLISH = 'QUESTION_PUBLISH';
    public const ANSWER_KEY_VIEW = 'QUESTION_ANSWER_KEY_VIEW';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW,
            self::MANAGE,
            self::REVIEW,
            self::PUBLISH,
            self::ANSWER_KEY_VIEW,
        ];
    }
}
