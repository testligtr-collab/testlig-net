<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminCurriculumActionRequest
{
    public const REASON_PUBLISH = 'curriculum_publish';
    public const REASON_RETIRE = 'curriculum_retire';
    public const REASON_ARCHIVE = 'curriculum_archive';
    public const REASON_DELETE = 'curriculum_delete';
    public const REASON_PASSIVE = 'curriculum_passive';
    public const REASON_ACTIVATE = 'curriculum_activate';

    #[Assert\NotBlank(message: 'İşlem gerekçesi gerekli.')]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Onay kutusu gerekli.')]
    public bool $confirm = false;
}
