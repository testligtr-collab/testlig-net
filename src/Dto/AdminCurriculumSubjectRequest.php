<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminCurriculumSubjectRequest
{
    #[Assert\NotBlank(message: 'Kod gerekli.')]
    public string $code = '';

    #[Assert\NotBlank(message: 'Ad gerekli.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'İşlem gerekçesi gerekli.')]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay kutusu gerekli.')]
    public bool $confirm = false;
}
