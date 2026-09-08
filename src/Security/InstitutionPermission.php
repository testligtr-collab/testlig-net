<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Institution-scoped authorization attributes for InstitutionVoter.
 */
final class InstitutionPermission
{
    public const VIEW = 'INSTITUTION_VIEW';
    public const MANAGE = 'INSTITUTION_MANAGE';
    public const MEMBERS_VIEW = 'INSTITUTION_MEMBERS_VIEW';
    public const MEMBERS_MANAGE = 'INSTITUTION_MEMBERS_MANAGE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE, self::MEMBERS_VIEW, self::MEMBERS_MANAGE];
    }
}
