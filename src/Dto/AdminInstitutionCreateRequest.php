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

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 180)]
    public string $name = '';

    #[Assert\NotNull]
    public ?InstitutionType $type = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $ownerUserId = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::REASON_ONBOARDING,
        self::REASON_OPERATOR_SETUP,
        self::REASON_MIGRATION_IMPORT,
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay gereklidir.')]
    public bool $confirm = false;
}
