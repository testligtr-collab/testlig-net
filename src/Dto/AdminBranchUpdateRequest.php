<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionBranchKind;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminBranchUpdateRequest
{
    public const REASON_CORRECTION = 'record_correction';
    public const REASON_OPERATOR = 'operator_correction';
    public const REASON_MIGRATION_IMPORT = 'migration_import';

    #[Assert\NotBlank(message: 'Ad gerekli.')]
    #[Assert\Length(min: 2, max: 180, minMessage: 'Ad en az {{ limit }} karakter olmalı.', maxMessage: 'Ad en fazla {{ limit }} karakter olabilir.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Kurum içi kod gerekli.')]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9-]{1,31}$/', message: 'Kod harf, rakam ve kısa çizgi içerebilir.')]
    public string $code = '';

    #[Assert\NotNull(message: 'Tür gerekli.')]
    public ?InstitutionBranchKind $kind = null;

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'İşlem gerekçesi gerekli.'),
        new Assert\Choice(choices: [
            self::REASON_CORRECTION,
            self::REASON_OPERATOR,
            self::REASON_MIGRATION_IMPORT,
        ], message: 'İşlem gerekçesi geçersiz.'),
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Değişikliği onaylamanız gerekir.')]
    public bool $confirm = false;
}
