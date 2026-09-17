<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminUserStatusRequest
{
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_ARCHIVE = 'archive';
    public const ACTION_REACTIVATE = 'reactivate';

    public const REASON_POLICY_VIOLATION = 'policy_violation';
    public const REASON_ACCOUNT_REVIEW = 'account_review';
    public const REASON_OPERATOR_CORRECTION = 'operator_correction';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::ACTION_SUSPEND,
        self::ACTION_ARCHIVE,
        self::ACTION_REACTIVATE,
    ])]
    public string $action = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::REASON_POLICY_VIOLATION,
        self::REASON_ACCOUNT_REVIEW,
        self::REASON_OPERATOR_CORRECTION,
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay gereklidir.')]
    public bool $confirm = false;
}
