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
        ];
    }
}
