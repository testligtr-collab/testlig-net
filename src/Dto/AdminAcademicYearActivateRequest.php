<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminAcademicYearActivateRequest
{
    public const REASON_LIFECYCLE = 'lifecycle_change';
    public const REASON_OPERATOR = 'operator_correction';

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'İşlem gerekçesi gerekli.'),
        new Assert\Choice(choices: [
            self::REASON_LIFECYCLE,
            self::REASON_OPERATOR,
        ], message: 'İşlem gerekçesi geçersiz.'),
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Aktifleştirmeyi onaylamanız gerekir.')]
    public bool $confirm = false;
}
