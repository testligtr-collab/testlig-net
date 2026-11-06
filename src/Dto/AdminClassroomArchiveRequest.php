<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminClassroomArchiveRequest
{
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'İşlem gerekçesi gerekli.'),
        new Assert\Choice(callback: [AdminClassroomCreateRequest::class, 'reasonChoices'], message: 'İşlem gerekçesi geçersiz.'),
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Arşivlemeyi onaylamanız gerekir.')]
    public bool $confirm = false;
}
