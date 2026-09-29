<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\UserRole;

/**
 * Turkish labels for the content workspace. Labels are a fixed map, never caller HTML.
 */
final class WorkspaceRoleLabels
{
    /**
     * @param list<string> $roles
     */
    public static function content(array $roles): string
    {
        foreach ([
            UserRole::HeadTeacher->value => 'Baş Öğretmen',
            UserRole::ExpertTeacher->value => 'Uzman Öğretmen',
            UserRole::Moderator->value => 'Moderatör',
            UserRole::Teacher->value => 'Öğretmen',
        ] as $role => $label) {
            if (\in_array($role, $roles, true)) {
                return $label;
            }
        }

        return 'İçerik';
    }
}
