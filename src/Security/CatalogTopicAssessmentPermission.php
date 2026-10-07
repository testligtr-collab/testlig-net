<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Authorization attributes for CatalogTopicAssessmentVoter (placement only).
 */
final class CatalogTopicAssessmentPermission
{
    public const CREATE = 'CATALOG_TOPIC_ASSESSMENT_CREATE';
    public const MANAGE = 'CATALOG_TOPIC_ASSESSMENT_MANAGE';
    public const PUBLISH = 'CATALOG_TOPIC_ASSESSMENT_PUBLISH';
    public const ARCHIVE = 'CATALOG_TOPIC_ASSESSMENT_ARCHIVE';
    public const VIEW = 'CATALOG_TOPIC_ASSESSMENT_VIEW';

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
