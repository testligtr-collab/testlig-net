<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SubjectCreateRequest
{
    #[Assert\NotBlank(message: 'Kod zorunludur.')]
    #[Assert\Length(max: 64)]
    public ?string $code = null;

    #[Assert\NotBlank(message: 'Ad zorunludur.')]
    #[Assert\Length(max: 180)]
    public ?string $name = null;
}
