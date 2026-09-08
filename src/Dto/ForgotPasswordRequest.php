<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Forgot-password request input. Never bind to User.
 */
final class ForgotPasswordRequest
{
    #[Assert\NotBlank(message: 'E-posta zorunludur.')]
    #[Assert\Email(message: 'Geçerli bir e-posta adresi girin.')]
    #[Assert\Length(max: 180, maxMessage: 'E-posta en fazla {{ limit }} karakter olabilir.')]
    public string $email = '';
}
