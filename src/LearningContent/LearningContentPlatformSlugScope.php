<?php

declare(strict_types=1);

namespace App\LearningContent;

/**
 * MariaDB STORED generated column for unique platform learning-content slugs.
 *
 * Expression: IF(scope = 'platform', slug, NULL)
 * UNIQUE(platform_slug_scope) — institution rows keep NULL and do not collide.
 */
final class LearningContentPlatformSlugScope
{
    public const COLUMN_NAME = 'platform_slug_scope';

    public const UNIQUE_INDEX_NAME = 'uniq_lc_platform_slug';
}
