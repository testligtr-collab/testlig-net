<?php

declare(strict_types=1);

namespace App\Onboarding;

/**
 * At-most-one pending application per user (teacher / institution).
 *
 * MariaDB UNIQUE treats NULLs as distinct, so a STORED generated column is
 * user_id only while status = pending; non-pending rows leave the column NULL
 * and may accumulate freely after reject / withdraw / supersede.
 */
final class OnboardingPendingOwnerScope
{
    public const COLUMN_NAME = 'pending_owner_id';

    public const TEACHER_TABLE = 'teacher_applications';

    public const INSTITUTION_TABLE = 'institution_applications';

    public const TEACHER_UNIQUE_INDEX = 'uniq_teacher_app_one_pending';

    public const INSTITUTION_UNIQUE_INDEX = 'uniq_inst_app_one_pending';

    /**
     * Exact SQL fragment used in Version20260921120000 (whitespace-insensitive
     * comparison in integrity tests lowercases and strips spaces).
     */
    public const GENERATION_EXPRESSION_SQL = "CASE WHEN `status` = 'pending' THEN `user_id` ELSE NULL END";

    private function __construct()
    {
    }

    public static function normalizedGenerationExpression(): string
    {
        return strtolower(preg_replace('/\s+/', '', self::GENERATION_EXPRESSION_SQL) ?? '');
    }
}
