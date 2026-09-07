<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public registration input. Never bind forms to the User entity.
 */
final class RegistrationRequest
{
    #[Assert\NotBlank(message: 'Ad zorunludur.')]
    #[Assert\Length(min: 1, max: 100, maxMessage: 'Ad en fazla {{ limit }} karakter olabilir.')]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Soyad zorunludur.')]
    #[Assert\Length(min: 1, max: 100, maxMessage: 'Soyad en fazla {{ limit }} karakter olabilir.')]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'E-posta zorunludur.')]
    #[Assert\Email(message: 'Geçerli bir e-posta adresi girin.')]
    #[Assert\Length(max: 180, maxMessage: 'E-posta en fazla {{ limit }} karakter olabilir.')]
    public string $email = '';

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

    #[Assert\IsTrue(message: 'Devam etmek için kullanım koşullarını ve gizlilik metnini kabul etmelisiniz.')]
    public bool $agreeTerms = false;
}
