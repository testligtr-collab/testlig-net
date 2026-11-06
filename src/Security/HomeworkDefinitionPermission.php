<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Draft homework authorization attributes for HomeworkDefinitionVoter.
 * VIEW and EDIT target a HomeworkDefinition; CREATE targets the Classroom the draft is prepared for.
 */
final class HomeworkDefinitionPermission
{
    public const VIEW = 'HOMEWORK_DEFINITION_VIEW';
    public const EDIT = 'HOMEWORK_DEFINITION_EDIT';
    public const CREATE = 'HOMEWORK_DEFINITION_CREATE';
    public const ASSIGN = 'HOMEWORK_DEFINITION_ASSIGN';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::EDIT, self::CREATE, self::ASSIGN];
    }
}
