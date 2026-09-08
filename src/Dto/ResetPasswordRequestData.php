<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * New password after a validated reset token.
 */
final class ResetPasswordRequestData
{
    #[Assert\NotBlank(message: 'Parola zorunludur.')]
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
    public string $plainPassword = '';
}
