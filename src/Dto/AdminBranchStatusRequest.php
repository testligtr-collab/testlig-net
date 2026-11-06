<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminBranchStatusRequest
{
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_ARCHIVE = 'archive';

    public const REASON_LIFECYCLE = 'lifecycle_change';
    public const REASON_POLICY = 'policy_enforcement';
    public const REASON_OPERATOR = 'operator_correction';

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Yeni durum gerekli.'),
        new Assert\Choice(choices: [
            self::ACTION_ACTIVATE,
            self::ACTION_SUSPEND,
            self::ACTION_ARCHIVE,
        ], message: 'Seçilen durum geçişi geçerli değil.'),
    ])]
    public string $action = '';

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'İşlem gerekçesi gerekli.'),
        new Assert\Choice(choices: [
            self::REASON_LIFECYCLE,
            self::REASON_POLICY,
            self::REASON_OPERATOR,
        ], message: 'İşlem gerekçesi geçersiz.'),
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Durum değişikliğini onaylamanız gerekir.')]
    public bool $confirm = false;
}
