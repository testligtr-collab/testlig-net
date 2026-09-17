<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminInstitutionStatusRequest
{
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_ARCHIVE = 'archive';

    public const REASON_LIFECYCLE = 'lifecycle_change';
    public const REASON_POLICY = 'policy_enforcement';
    public const REASON_OPERATOR = 'operator_correction';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::ACTION_ACTIVATE,
        self::ACTION_SUSPEND,
        self::ACTION_ARCHIVE,
    ])]
    public string $action = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::REASON_LIFECYCLE,
        self::REASON_POLICY,
        self::REASON_OPERATOR,
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay gereklidir.')]
    public bool $confirm = false;
}
