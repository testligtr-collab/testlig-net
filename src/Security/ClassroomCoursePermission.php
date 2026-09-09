<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Classroom-course authorization attributes for ClassroomCourseVoter.
 */
final class ClassroomCoursePermission
{
    public const VIEW = 'CLASSROOM_COURSE_VIEW';
    public const MANAGE = 'CLASSROOM_COURSE_MANAGE';
    public const TEACHERS_VIEW = 'CLASSROOM_COURSE_TEACHERS_VIEW';
    public const TEACHERS_MANAGE = 'CLASSROOM_COURSE_TEACHERS_MANAGE';
    public const CURRICULUM_VIEW = 'CLASSROOM_COURSE_CURRICULUM_VIEW';

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
            self::CURRICULUM_VIEW,
        ];
    }
}
