<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Authorization attributes for CatalogTopicLessonVoter (placement only).
 */
final class CatalogTopicLessonPermission
{
    public const CREATE = 'CATALOG_TOPIC_LESSON_CREATE';
    public const MANAGE = 'CATALOG_TOPIC_LESSON_MANAGE';
    public const PUBLISH = 'CATALOG_TOPIC_LESSON_PUBLISH';
    public const ARCHIVE = 'CATALOG_TOPIC_LESSON_ARCHIVE';
    public const VIEW = 'CATALOG_TOPIC_LESSON_VIEW';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CREATE,
            self::MANAGE,
            self::PUBLISH,
            self::ARCHIVE,
            self::VIEW,
        ];
    }
}
