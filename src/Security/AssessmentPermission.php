<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Assessment authoring authorization attributes for AssessmentVoter.
 */
final class AssessmentPermission
{
    public const VIEW = 'ASSESSMENT_VIEW';
    public const CREATE = 'ASSESSMENT_CREATE';
    public const REVISE = 'ASSESSMENT_REVISE';
    public const SUBMIT = 'ASSESSMENT_SUBMIT';
    public const REVIEW = 'ASSESSMENT_REVIEW';
    public const PUBLISH = 'ASSESSMENT_PUBLISH';
    public const ARCHIVE = 'ASSESSMENT_ARCHIVE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::REVISE,
            self::SUBMIT,
            self::REVIEW,
            self::PUBLISH,
            self::ARCHIVE,
        ];
    }
}
