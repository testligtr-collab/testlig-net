<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Admin operations panel authorization attributes for {@see AdminVoter}.
 */
final class AdminPermission
{
    public const ADMIN_SHELL_ACCESS = 'ADMIN_SHELL_ACCESS';
    public const ADMIN_SYSTEM_VIEW = 'ADMIN_SYSTEM_VIEW';
    public const ADMIN_PAYMENT_OPS = 'ADMIN_PAYMENT_OPS';
    public const ADMIN_AUDIT_VIEW = 'ADMIN_AUDIT_VIEW';
    public const ADMIN_DEAD_LETTER_REQUEUE = 'ADMIN_DEAD_LETTER_REQUEUE';
    public const ADMIN_USERS_VIEW = 'ADMIN_USERS_VIEW';
    public const ADMIN_USERS_MANAGE = 'ADMIN_USERS_MANAGE';
    public const ADMIN_INSTITUTIONS_VIEW = 'ADMIN_INSTITUTIONS_VIEW';
    public const ADMIN_INSTITUTIONS_CREATE = 'ADMIN_INSTITUTIONS_CREATE';
    public const ADMIN_INSTITUTIONS_MANAGE = 'ADMIN_INSTITUTIONS_MANAGE';
    public const ADMIN_BRANCHES_VIEW = 'ADMIN_BRANCHES_VIEW';
    public const ADMIN_BRANCHES_CREATE = 'ADMIN_BRANCHES_CREATE';
    public const ADMIN_BRANCHES_MANAGE = 'ADMIN_BRANCHES_MANAGE';
    public const ADMIN_ACADEMIC_YEARS_VIEW = 'ADMIN_ACADEMIC_YEARS_VIEW';
    public const ADMIN_ACADEMIC_YEARS_CREATE = 'ADMIN_ACADEMIC_YEARS_CREATE';
    public const ADMIN_ACADEMIC_YEARS_MANAGE = 'ADMIN_ACADEMIC_YEARS_MANAGE';
    public const ADMIN_MEMBERSHIPS_VIEW = 'ADMIN_MEMBERSHIPS_VIEW';
    public const ADMIN_MEMBERSHIPS_MANAGE = 'ADMIN_MEMBERSHIPS_MANAGE';
    public const ADMIN_CATALOG_VIEW = 'ADMIN_CATALOG_VIEW';
    public const ADMIN_CATALOG_MANAGE = 'ADMIN_CATALOG_MANAGE';
    public const ADMIN_CATALOG_MAP_CANONICAL = 'ADMIN_CATALOG_MAP_CANONICAL';
    public const ADMIN_LEARNING_CONTENT_VIEW = 'ADMIN_LEARNING_CONTENT_VIEW';
    public const ADMIN_LEARNING_CONTENT_MANAGE = 'ADMIN_LEARNING_CONTENT_MANAGE';
    public const ADMIN_QUESTION_VIEW = 'ADMIN_QUESTION_VIEW';
    public const ADMIN_TEST_VIEW = 'ADMIN_TEST_VIEW';
    public const ADMIN_NEWS_MANAGE = 'ADMIN_NEWS_MANAGE';
    public const ADMIN_SECTION_ENTRY = 'ADMIN_SECTION_ENTRY';
    public const ADMIN_MODULE_PERMISSIONS_MANAGE = 'ADMIN_MODULE_PERMISSIONS_MANAGE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN_SHELL_ACCESS,
            self::ADMIN_SYSTEM_VIEW,
            self::ADMIN_PAYMENT_OPS,
            self::ADMIN_AUDIT_VIEW,
            self::ADMIN_DEAD_LETTER_REQUEUE,
            self::ADMIN_USERS_VIEW,
            self::ADMIN_USERS_MANAGE,
            self::ADMIN_INSTITUTIONS_VIEW,
            self::ADMIN_INSTITUTIONS_CREATE,
            self::ADMIN_INSTITUTIONS_MANAGE,
            self::ADMIN_BRANCHES_VIEW,
            self::ADMIN_BRANCHES_CREATE,
            self::ADMIN_BRANCHES_MANAGE,
            self::ADMIN_ACADEMIC_YEARS_VIEW,
            self::ADMIN_ACADEMIC_YEARS_CREATE,
            self::ADMIN_ACADEMIC_YEARS_MANAGE,
            self::ADMIN_MEMBERSHIPS_VIEW,
            self::ADMIN_MEMBERSHIPS_MANAGE,
            self::ADMIN_CATALOG_VIEW,
            self::ADMIN_CATALOG_MANAGE,
            self::ADMIN_CATALOG_MAP_CANONICAL,
            self::ADMIN_LEARNING_CONTENT_VIEW,
            self::ADMIN_LEARNING_CONTENT_MANAGE,
            self::ADMIN_QUESTION_VIEW,
            self::ADMIN_TEST_VIEW,
            self::ADMIN_NEWS_MANAGE,
            self::ADMIN_SECTION_ENTRY,
            self::ADMIN_MODULE_PERMISSIONS_MANAGE,
        ];
    }
}
