<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Learning content authorization attributes for LearningContentVoter.
 */
final class LearningContentPermission
{
    public const CREATE = 'LEARNING_CONTENT_CREATE';
    public const MANAGE = 'LEARNING_CONTENT_MANAGE';
    public const SUBMIT_REVIEW = 'LEARNING_CONTENT_SUBMIT_REVIEW';
    public const RETURN_DRAFT = 'LEARNING_CONTENT_RETURN_DRAFT';
    public const PUBLISH = 'LEARNING_CONTENT_PUBLISH';
    public const ARCHIVE = 'LEARNING_CONTENT_ARCHIVE';
    public const VIEW_METADATA = 'LEARNING_CONTENT_VIEW_METADATA';
    public const ATTACH_ASSET = 'LEARNING_CONTENT_ATTACH_ASSET';
    public const MANAGE_ALIGNMENT = 'LEARNING_CONTENT_MANAGE_ALIGNMENT';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CREATE,
            self::MANAGE,
            self::SUBMIT_REVIEW,
            self::RETURN_DRAFT,
            self::PUBLISH,
            self::ARCHIVE,
            self::VIEW_METADATA,
            self::ATTACH_ASSET,
            self::MANAGE_ALIGNMENT,
        ];
    }
}
