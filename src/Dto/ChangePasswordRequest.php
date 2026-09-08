<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Authenticated password change input.
 */
final class ChangePasswordRequest
{
    #[Assert\NotBlank(message: 'Mevcut parola zorunludur.')]
    public string $currentPassword = '';

    #[Assert\NotBlank(message: 'Yeni parola zorunludur.')]
    #[Assert\Length(
        min: 12,
        max: 4096,
        minMessage: 'Parola en az {{ limit }} karakter olmalıdır.',
        maxMessage: 'Parola çok uzun.',
    )]
    #[Assert\PasswordStrength(
        minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
        message: 'Parola yeterince güçlü değil. Büyük/küçük harf, rakam ve özel karakter karışımı kullanın.',
    )]
    public string $newPassword = '';
}
