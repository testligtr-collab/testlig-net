<?php

declare(strict_types=1);

namespace App\Enum;

enum InstitutionMembershipRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Teacher = 'teacher';
    case Staff = 'staff';

    /**
     * Roles an owner may assign/manage (not owner itself via changeRole).
     *
     * @return list<self>
     */
    public static function assignableByOwner(): array
    {
        return [self::Manager, self::Teacher, self::Staff];
    }

    /**
     * Roles a manager may assign/manage.
     *
     * @return list<self>
     */
    public static function assignableByManager(): array
    {
        return [self::Teacher, self::Staff];
    }
}
