<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Authenticated password change input.
 */
final class ChangePasswordRequest
{
    #[Assert\NotBlank(message: 'Mevcut parola zorunludur.')]
    public string $currentPassword = '';

    #[UserPassword(notBlankMessage: 'Yeni parola zorunludur.')]
    public string $newPassword = '';
}
