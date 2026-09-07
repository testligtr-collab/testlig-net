<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Global system-level roles stored on the central User account.
 *
 * Domain-scoped memberships (institution, classroom, teacher/student links)
 * will be modelled separately in later phases — do not overload this enum.
 */
enum UserRole: string
{
    case User = 'ROLE_USER';
    case Student = 'ROLE_STUDENT';
    case Parent = 'ROLE_PARENT';
    case Teacher = 'ROLE_TEACHER';
    case ExpertTeacher = 'ROLE_EXPERT_TEACHER';
    case HeadTeacher = 'ROLE_HEAD_TEACHER';
    case InstitutionManager = 'ROLE_INSTITUTION_MANAGER';
    case Moderator = 'ROLE_MODERATOR';
    case Admin = 'ROLE_ADMIN';
    case SuperAdmin = 'ROLE_SUPER_ADMIN';

    /**
     * Roles that may be assigned as the initial role when creating a user.
     *
     * @return list<self>
     */
    public static function assignableAtCreation(): array
    {
        return [
            self::Student,
            self::Parent,
            self::Teacher,
            self::ExpertTeacher,
            self::HeadTeacher,
            self::InstitutionManager,
            self::Moderator,
        ];
    }

    public function isPrivilegedBootstrapRole(): bool
    {
        return self::Admin === $this || self::SuperAdmin === $this;
    }
}
