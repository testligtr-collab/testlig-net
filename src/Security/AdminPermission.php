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
    public const ADMIN_MEMBERSHIPS_VIEW = 'ADMIN_MEMBERSHIPS_VIEW';
    public const ADMIN_MEMBERSHIPS_MANAGE = 'ADMIN_MEMBERSHIPS_MANAGE';

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
            self::ADMIN_MEMBERSHIPS_VIEW,
            self::ADMIN_MEMBERSHIPS_MANAGE,
        ];
    }
}
