<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public institution onboarding application input. Never bind to Institution entity.
 */
final class InstitutionApplicationRequest
{
    #[Assert\NotBlank(message: 'Kurum adı zorunludur.')]
    #[Assert\Length(min: 2, max: 180, maxMessage: 'Kurum adı en fazla {{ limit }} karakter olabilir.')]
    public string $proposedName = '';

    #[Assert\NotNull(message: 'Kurum türü seçiniz.')]
    public ?InstitutionType $proposedType = null;

    #[Assert\IsTrue(message: 'Başvurunun inceleneceğini ve erişimin henüz açılmadığını onaylamanız gerekir.')]
    public bool $acknowledgePendingReview = false;
}
