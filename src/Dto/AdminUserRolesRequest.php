<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminUserRolesRequest
{
    public const REASON_PRIVILEGE_ADJUSTMENT = 'privilege_adjustment';
    public const REASON_SUPPORT_ESCALATION = 'support_escalation';
    public const REASON_POLICY_ENFORCEMENT = 'policy_enforcement';

    /** @var list<string> */
    public array $roles = [];

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::REASON_PRIVILEGE_ADJUSTMENT,
        self::REASON_SUPPORT_ESCALATION,
        self::REASON_POLICY_ENFORCEMENT,
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay gereklidir.')]
    public bool $confirm = false;
}
