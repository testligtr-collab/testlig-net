<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Classroom-scoped authorization attributes for ClassroomVoter.
 */
final class ClassroomPermission
{
    public const VIEW = 'CLASSROOM_VIEW';
    public const MANAGE = 'CLASSROOM_MANAGE';
    public const TEACHERS_VIEW = 'CLASSROOM_TEACHERS_VIEW';
    public const TEACHERS_MANAGE = 'CLASSROOM_TEACHERS_MANAGE';
    public const STUDENTS_VIEW = 'CLASSROOM_STUDENTS_VIEW';
    public const STUDENTS_MANAGE = 'CLASSROOM_STUDENTS_MANAGE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW,
            self::MANAGE,
            self::TEACHERS_VIEW,
            self::TEACHERS_MANAGE,
            self::STUDENTS_VIEW,
            self::STUDENTS_MANAGE,
        ];
    }
}
