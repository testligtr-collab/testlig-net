<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionMembershipRole;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminMembershipRoleRequest
{
    public const REASON_ROLE_ADJUSTMENT = 'role_adjustment';
    public const REASON_POLICY_ENFORCEMENT = 'policy_enforcement';
    public const REASON_OPERATOR_CORRECTION = 'operator_correction';

    #[Assert\NotNull]
    public ?InstitutionMembershipRole $role = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::REASON_ROLE_ADJUSTMENT,
        self::REASON_POLICY_ENFORCEMENT,
        self::REASON_OPERATOR_CORRECTION,
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay gereklidir.')]
    public bool $confirm = false;
}
