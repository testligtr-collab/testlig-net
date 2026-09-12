<?php

declare(strict_types=1);

namespace App\LearningContent;

/**
 * MariaDB STORED generated column for at-most-one primary alignment per revision.
 *
 * Expression: IF(is_primary, revision_id, NULL)
 * UNIQUE(primary_revision_scope_id) — multiple NULLs allowed for non-primary rows.
 */
final class LearningContentRevisionPrimaryAlignmentScope
{
    public const COLUMN_NAME = 'primary_revision_scope_id';

    public const UNIQUE_INDEX_NAME = 'uniq_lcoa_one_primary_per_revision';
}
