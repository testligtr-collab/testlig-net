<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Curriculum program authorization attributes for CurriculumVoter.
 */
final class CurriculumPermission
{
    public const VIEW = 'CURRICULUM_VIEW';
    public const MANAGE = 'CURRICULUM_MANAGE';
    public const PUBLISH = 'CURRICULUM_PUBLISH';
    public const RETIRE = 'CURRICULUM_RETIRE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW,
            self::MANAGE,
            self::PUBLISH,
            self::RETIRE,
        ];
    }
}
