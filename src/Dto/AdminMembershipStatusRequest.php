<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminMembershipStatusRequest
{
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_REACTIVATE = 'reactivate';
    public const ACTION_END = 'end';

    public const REASON_POLICY = 'policy_enforcement';
    public const REASON_LIFECYCLE = 'membership_lifecycle';
    public const REASON_OPERATOR = 'operator_correction';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::ACTION_SUSPEND,
        self::ACTION_REACTIVATE,
        self::ACTION_END,
    ])]
    public string $action = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::REASON_POLICY,
        self::REASON_LIFECYCLE,
        self::REASON_OPERATOR,
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay gereklidir.')]
    public bool $confirm = false;
}
