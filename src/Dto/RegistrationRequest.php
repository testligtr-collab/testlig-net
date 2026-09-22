<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AccountType;
use App\Enum\RegistrationFlow;
use App\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public registration input. Never bind forms to the User entity.
 *
 * accountType / flow are set by the controller from the URL path — not form fields.
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

    #[UserPassword]
    public string $plainPassword = '';

    /**
     * Server-set from /kayit/{flow}. Forms must not expose this.
     */
    public RegistrationFlow $flow = RegistrationFlow::Student;

    /**
     * Derived for student/parent only. Forms must not expose this.
     */
    public AccountType $accountType = AccountType::Student;

    #[Assert\IsTrue(message: 'Devam etmek için kullanım koşullarını ve gizlilik metnini kabul etmelisiniz.')]
    public bool $agreeTerms = false;
}
