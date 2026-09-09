<?php

declare(strict_types=1);

namespace App\Curriculum;

use Symfony\Component\Uid\Uuid;

/**
 * Sibling-position scope for curriculum_topics.
 *
 * MariaDB treats NULLs as distinct in UNIQUE indexes, so root topics
 * (parent_id IS NULL) cannot rely on UNIQUE(unit_id, parent_id, position).
 * A STORED generated column coalesces NULL parents to this sentinel so
 * UNIQUE(unit_id, position_scope_id, position) enforces root and child scopes.
 *
 * Sentinel is the nil UUID (all-zero). Application topic IDs are UuidV7 and
 * must never equal this value.
 */
final class CurriculumTopicPositionScope
{
    public const ROOT_SENTINEL_RFC4122 = '00000000-0000-0000-0000-000000000000';

    /** 16 zero bytes — MariaDB 0x0000…0000 / IFNULL(parent_id, …) target. */
    public const ROOT_SENTINEL_HEX = '00000000000000000000000000000000';

    /**
     * Exact generation expression used in migration + schema listener.
     * Keep in sync with Version20260909120000 and CurriculumTopicPositionScopeSchemaListener.
     */
    public const GENERATION_EXPRESSION = 'ifnull(`parent_id`,0x00000000000000000000000000000000)';

    public const COLUMN_NAME = 'position_scope_id';

    public const UNIQUE_INDEX_NAME = 'uniq_curriculum_topic_unit_scope_position';

    private function __construct()
    {
    }

    public static function rootSentinel(): Uuid
    {
        return Uuid::fromString(self::ROOT_SENTINEL_RFC4122);
    }

    public static function rootSentinelBinary(): string
    {
        return self::rootSentinel()->toBinary();
    }

    public static function assertNotRootSentinel(Uuid $id): void
    {
        if ($id->equals(self::rootSentinel())) {
            throw new \InvalidArgumentException(
                'Curriculum topic id must not be the nil UUID root position-scope sentinel.',
            );
        }
    }
}
