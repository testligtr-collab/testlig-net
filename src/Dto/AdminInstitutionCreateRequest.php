<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionType;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminInstitutionCreateRequest
{
    public const REASON_ONBOARDING = 'institution_onboarding';
    public const REASON_OPERATOR_SETUP = 'operator_setup';
    public const REASON_MIGRATION_IMPORT = 'migration_import';

    #[Assert\NotBlank(message: 'Kurum adı gerekli.')]
    #[Assert\Length(min: 2, max: 180, minMessage: 'Kurum adı en az {{ limit }} karakter olmalı.', maxMessage: 'Kurum adı en fazla {{ limit }} karakter olabilir.')]
    public string $name = '';

    #[Assert\NotNull(message: 'Kurum türü gerekli.')]
    public ?InstitutionType $type = null;

    #[Assert\NotBlank(message: 'İlk sahip hesabı gerekli.')]
    #[Assert\Uuid(message: 'Seçilen hesap geçersiz.')]
    public string $ownerUserId = '';

    #[Assert\NotBlank(message: 'İşlem gerekçesi gerekli.')]
    #[Assert\Choice(choices: [
        self::REASON_ONBOARDING,
        self::REASON_OPERATOR_SETUP,
        self::REASON_MIGRATION_IMPORT,
    ], message: 'İşlem gerekçesi geçersiz.')]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Kurum oluşturmayı onaylamanız gerekir.')]
    public bool $confirm = false;
}
